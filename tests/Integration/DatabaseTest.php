<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Testo\Assert;
use Laratesto\Attribute\DatabaseMigrations;
use Laratesto\Attribute\DatabaseTransactions;
use Laratesto\Attribute\RefreshDatabase;
use Laratesto\Testing\InteractsWithLaravel;

final class DatabaseTest
{
    use InteractsWithLaravel;

    private bool $schemaWasReadyDuringSetUp = false;

    private int $transactionLevelDuringSetUp = 0;

    protected function setUpLaravel(): void
    {
        $this->schemaWasReadyDuringSetUp = Schema::hasTable('things');
        $this->transactionLevelDuringSetUp = $this->make('db')->transactionLevel();
    }

    public function testWithoutAttributesTheDatabaseIsEmpty(): void
    {
        Assert::false(Schema::hasTable('things'));
    }

    #[RefreshDatabase]
    public function testRefreshDatabaseRunsMigrations(): void
    {
        Assert::true($this->schemaWasReadyDuringSetUp);
        Assert::true(Schema::hasTable('things'));

        DB::table('things')->insert(['name' => 'first']);
        DB::table('things')->insert(['name' => 'second']);

        Assert::same(DB::table('things')->count(), 2);
    }

    #[RefreshDatabase]
    public function testRefreshDatabaseStartsFromACleanState(): void
    {
        Assert::true(Schema::hasTable('things'));
        Assert::same(DB::table('things')->count(), 0);
    }

    #[DatabaseTransactions]
    public function testDatabaseTransactionsWrapsTheTestInATransaction(): void
    {
        Assert::same($this->transactionLevelDuringSetUp, 1);
        Assert::same($this->make('db')->transactionLevel(), 1);
    }

    #[DatabaseMigrations]
    public function testDatabaseMigrationsRunsFreshMigrations(): void
    {
        Assert::true($this->schemaWasReadyDuringSetUp);
        Assert::true(Schema::hasTable('things'));
        DB::table('things')->insert(['name' => 'temporary']);
        Assert::same(DB::table('things')->count(), 1);
    }
}
