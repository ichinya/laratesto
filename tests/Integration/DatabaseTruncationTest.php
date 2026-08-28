<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use App\Database\ThingsSeeder;
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

    #[DatabaseTruncation(seed: true, seeder: ThingsSeeder::class)]
    public function testFirstRunOverAFreshDatabaseMigrates(): void
    {
        Assert::true($this->schemaWasReadyDuringSetUp);
        Assert::true(Schema::hasTable('things'));
        Assert::same(DB::table('things')->pluck('name')->all(), ['seeded']);

        DB::table('things')->insert(['name' => 'leftover']);

        Assert::same(DB::table('things')->count(), 2);
    }

    #[DatabaseTruncation]
    public function testSubsequentRunsTruncateInsteadOfMigrating(): void
    {
        Assert::true($this->schemaWasReadyDuringSetUp);
        Assert::same($this->thingsCountDuringSetUp, 0);
    }

    #[DatabaseTruncation(seed: true, seeder: ThingsSeeder::class)]
    public function testSubsequentRunTruncatesThenUsesTheSpecificSeeder(): void
    {
        Assert::same(DB::table('things')->pluck('name')->all(), ['seeded']);
    }

    #[DatabaseTruncation(tables: ['things'])]
    public function testTableFilterTruncatesOnlyListedTables(): void
    {
        Assert::same($this->thingsCountDuringSetUp, 0);

        DB::table('things')->insert(['name' => 'kept']);

        Assert::same(DB::table('things')->count(), 1);
    }

    #[DatabaseTruncation(tables: [])]
    public function testEmptyTableSelectionUsesTheDefaultTruncationSet(): void
    {
        Assert::true($this->schemaWasReadyDuringSetUp);
        Assert::same($this->thingsCountDuringSetUp, 0);
    }

    #[DatabaseTruncation(exceptTables: ['audit_entries'])]
    public function testExceptTablesKeepsTheNamedTableAndAlwaysKeepsMigrations(): void
    {
        DB::table('audit_entries')->insert(['message' => 'keep']);

        Assert::same(DB::table('audit_entries')->count(), 1);
        Assert::true(Schema::hasTable('migrations'));
        Assert::true(DB::table('migrations')->count() > 0);
    }

    #[DatabaseTruncation(exceptTables: ['audit_entries'])]
    public function testExceptTablesPersistsAcrossTheNextTruncation(): void
    {
        Assert::same(DB::table('audit_entries')->count(), 1);
    }

    #[DatabaseTruncation(tables: ['audit_entries'], exceptTables: ['audit_entries'])]
    public function testTablesSelectionTakesPriorityOverExceptTables(): void
    {
        Assert::same(DB::table('audit_entries')->count(), 0);
    }

    #[DatabaseTruncation(connections: ['sqlite', 'secondary'])]
    public function testEverySelectedConnectionIsMigratedAndTruncated(): void
    {
        $database = $this->make('db');

        foreach (['sqlite', 'secondary'] as $name) {
            $connection = $database->connection($name);
            Assert::true($connection->getSchemaBuilder()->hasTable('things'));
            $connection->table('things')->insert(['name' => $name]);
            Assert::same($connection->table('things')->count(), 1);
        }
    }

    #[DatabaseTruncation(connections: ['sqlite', 'secondary'])]
    public function testEverySelectedConnectionStartsTruncated(): void
    {
        $database = $this->make('db');

        Assert::same($database->connection('sqlite')->table('things')->count(), 0);
        Assert::same($database->connection('secondary')->table('things')->count(), 0);
    }
}
