<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use App\Database\ThingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Testo\Assert;
use Laratesto\Attribute\DatabaseMigrations;
use Laratesto\Attribute\DatabaseTransactions;
use Laratesto\Attribute\RefreshDatabase;
use Laratesto\Pipeline\Internal\DatabaseRuntime;
use Laratesto\Pipeline\Internal\DatabaseTransactionScope;
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

        // This class owns an explicit first-run migrate/seed scenario. Other
        // integration classes may already have exercised Laravel's global state.
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
    }

    #[RefreshDatabase(seed: true, seeder: ThingsSeeder::class)]
    public function testRefreshDatabaseUsesTheSpecificSeederBeforeTheTransaction(): void
    {
        Assert::same(DB::table('things')->pluck('name')->all(), ['seeded']);
    }

    #[RefreshDatabase]
    public function testRefreshDatabaseRunsMigrations(): void
    {
        Assert::true($this->schemaWasReadyDuringSetUp);
        Assert::true(Schema::hasTable('things'));

        DB::table('things')->insert(['name' => 'first']);
        DB::table('things')->insert(['name' => 'second']);

        Assert::same(DB::table('things')->count(), 3);
    }

    #[RefreshDatabase]
    public function testRefreshDatabaseStartsFromACleanState(): void
    {
        Assert::true(Schema::hasTable('things'));
        Assert::same(DB::table('things')->pluck('name')->all(), ['seeded']);
    }

    #[DatabaseTransactions]
    public function testDatabaseTransactionsWrapsTheTestInATransaction(): void
    {
        Assert::same($this->transactionLevelDuringSetUp, 1);
        Assert::same($this->make('db')->transactionLevel(), 1);
    }

    #[RefreshDatabase(connections: ['sqlite', 'secondary'])]
    public function testRefreshDatabaseMigratesAndTransactsEverySelectedConnection(): void
    {
        $database = $this->make('db');

        foreach (['sqlite', 'secondary'] as $name) {
            $connection = $database->connection($name);
            Assert::true($connection->getSchemaBuilder()->hasTable('things'));
            Assert::same($connection->transactionLevel(), 1);
            $connection->table('things')->insert(['name' => $name]);
            Assert::same($connection->table('things')->count(), 1);
        }
    }

    #[RefreshDatabase(connections: ['sqlite', 'secondary'])]
    public function testRefreshDatabaseRollsBackEverySelectedConnection(): void
    {
        $database = $this->make('db');

        Assert::same($database->connection('sqlite')->table('things')->count(), 0);
        Assert::same($database->connection('secondary')->table('things')->count(), 0);
    }

    #[DatabaseTransactions(connections: ['sqlite', 'secondary'])]
    public function testDatabaseTransactionsBeginsEverySelectedConnection(): void
    {
        $database = $this->make('db');

        Assert::same($database->connection('sqlite')->transactionLevel(), 1);
        Assert::same($database->connection('secondary')->transactionLevel(), 1);
        Assert::true($this->app()->bound('db.transactions'));
    }

    public function testTransactionScopeCleansAConnectionWhenALaterBeginFails(): void
    {
        $scope = new DatabaseTransactionScope($this->app(), ['sqlite', 'missing']);
        $failed = false;

        try {
            $scope->begin();
        } catch (\Throwable) {
            $failed = true;
        }

        Assert::true($failed, 'The deliberately missing second connection must fail.');
        Assert::same($this->make('db')->connection('sqlite')->transactionLevel(), 0);
        Assert::false($this->app()->bound('db.transactions'));
    }

    public function testMigratedSchemaDetectionAppliesTheConnectionPrefixOnce(): void
    {
        $connection = $this->make('db')->connection('sqlite');
        $originalPrefix = $connection->getTablePrefix();
        $connection->setTablePrefix('prefixed_');

        try {
            $connection->getSchemaBuilder()->create('migrations', static function ($table): void {
                $table->increments('id');
            });

            Assert::same(DatabaseRuntime::migrationsTable($this->app(), 'sqlite'), 'prefixed_migrations');
            Assert::true(DatabaseRuntime::schemaIsMigrated($this->app(), 'sqlite'));
        } finally {
            $connection->getSchemaBuilder()->dropIfExists('migrations');
            $connection->setTablePrefix($originalPrefix);
        }
    }

    #[DatabaseMigrations(seed: true, seeder: ThingsSeeder::class)]
    public function testDatabaseMigrationsRunsFreshMigrations(): void
    {
        Assert::true($this->schemaWasReadyDuringSetUp);
        Assert::true(Schema::hasTable('things'));
        Assert::same(DB::table('things')->pluck('name')->all(), ['seeded']);
        DB::table('things')->insert(['name' => 'temporary']);
        Assert::same(DB::table('things')->count(), 2);
    }
}
