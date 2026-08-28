<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laratesto\Attribute\DatabaseTruncation;
use Laratesto\Testing\InteractsWithLaravel;
use Testo\Assert;

final class DatabaseTruncationTest
{
    use InteractsWithLaravel;

    private bool $schemaWasReadyDuringSetUp = false;

    private int $thingsCountDuringSetUp = -1;

    protected function setUpLaravel(): void
    {
        $this->schemaWasReadyDuringSetUp = Schema::hasTable('things');
        $this->thingsCountDuringSetUp = $this->schemaWasReadyDuringSetUp
            ? DB::table('things')->count()
            : -1;
    }

    #[DatabaseTruncation]
    public function testFirstRunOverAFreshDatabaseMigrates(): void
    {
        Assert::true($this->schemaWasReadyDuringSetUp);
        Assert::true(Schema::hasTable('things'));

        DB::table('things')->insert(['name' => 'leftover']);

        Assert::same(DB::table('things')->count(), 1);
    }

    #[DatabaseTruncation]
    public function testSubsequentRunsTruncateInsteadOfMigrating(): void
    {
        Assert::true($this->schemaWasReadyDuringSetUp);
        Assert::same($this->thingsCountDuringSetUp, 0);
    }

    #[DatabaseTruncation(tables: ['things'])]
    public function testTableFilterTruncatesOnlyListedTables(): void
    {
        Assert::same($this->thingsCountDuringSetUp, 0);

        DB::table('things')->insert(['name' => 'kept']);

        Assert::same(DB::table('things')->count(), 1);
    }

    #[DatabaseTruncation]
    public function testListedTableIsTruncatedAgain(): void
    {
        Assert::true($this->schemaWasReadyDuringSetUp);
        Assert::same($this->thingsCountDuringSetUp, 0);
    }
}
