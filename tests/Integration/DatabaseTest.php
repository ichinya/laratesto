<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use App\Database\ThingsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
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
        $application = $this->app();
        $frameworkManager = $application->make('db.transactions');

        $scope = new DatabaseTransactionScope($application, ['sqlite', 'missing']);
        $failed = false;

        try {
            $scope->begin();
        } catch (\Throwable) {
            $failed = true;
        }

        Assert::true($failed, 'The deliberately missing second connection must fail.');
        Assert::same($this->make('db')->connection('sqlite')->transactionLevel(), 0);

        // The scope only borrowed the container slot: the framework's own
        // singleton binding survives even a failed begin.
        Assert::true($application->bound('db.transactions'));
        Assert::same($application->make('db.transactions'), $frameworkManager);
    }

    public function testTransactionScopeRestoresAPreExistingManagerInstance(): void
    {
        $application = $this->app();
        $owned = new DatabaseTransactionsManager(['sqlite']);
        $application->instance('db.transactions', $owned);

        $scope = new DatabaseTransactionScope($application, ['sqlite']);
        $scope->begin();

        // The scope swaps in its own manager for the test run only.
        Assert::notSame($application->make('db.transactions'), $owned);

        $scope->close();

        Assert::true($application->bound('db.transactions'));
        Assert::same($application->make('db.transactions'), $owned);
        Assert::same($this->make('db')->connection('sqlite')->transactionLevel(), 0);
    }

    public function testTransactionScopeKeepsAPreExistingLazyBindingResolvable(): void
    {
        $application = $this->app();
        $application->bind('db.transactions', fn () => new DatabaseTransactionsManager(['sqlite']));

        $scope = new DatabaseTransactionScope($application, ['sqlite']);
        $scope->begin();
        $scope->close();

        // The factory binding itself must survive the scope, not just an instance.
        Assert::true($application->bound('db.transactions'));
        Assert::instanceOf($application->make('db.transactions'), DatabaseTransactionsManager::class);
        Assert::same($this->make('db')->connection('sqlite')->transactionLevel(), 0);
    }

    public function testTransactionScopeKeepsAStableNonSharedFactoryUnfrozen(): void
    {
        $application = $this->app();
        $stable = new DatabaseTransactionsManager(['sqlite']);
        $resolutions = 0;
        $application->bind('db.transactions', function () use ($stable, &$resolutions) {
            $resolutions++;

            return $stable;
        });

        $scope = new DatabaseTransactionScope($application, ['sqlite']);
        $scope->begin();
        $scope->close();

        // The factory deliberately returns one stable object, yet the
        // binding must never be frozen into a stored instance.
        Assert::true($application->bound('db.transactions'));
        Assert::same($application->make('db.transactions'), $stable);
        Assert::same($resolutions, 1);

        // The next resolution still goes through the factory, not a copy.
        Assert::same($application->make('db.transactions'), $stable);
        Assert::same($resolutions, 2);
        Assert::same($this->make('db')->connection('sqlite')->transactionLevel(), 0);
    }

    public function testNestedScopesRestoreTheOuterTransactionManager(): void
    {
        $application = $this->app();

        $frameworkManager = $application->make('db.transactions');

        $outer = new DatabaseTransactionScope($application, ['sqlite']);
        $outer->begin();
        $outerManager = $application->make('db.transactions');

        $inner = new DatabaseTransactionScope($application, ['sqlite']);
        $inner->begin();
        Assert::notSame($application->make('db.transactions'), $outerManager);

        $inner->close();

        // The inner scope hands ownership back instead of unbinding the container.
        Assert::true($application->bound('db.transactions'));
        Assert::same($application->make('db.transactions'), $outerManager);

        $outer->close();

        // Once the outer scope unwinds, the framework's own singleton is back.
        Assert::true($application->bound('db.transactions'));
        Assert::same($application->make('db.transactions'), $frameworkManager);
        Assert::same($this->make('db')->connection('sqlite')->transactionLevel(), 0);
    }

    public function testCloseWithoutBeginLeavesTheContainerUntouched(): void
    {
        $application = $this->app();
        $owned = new DatabaseTransactionsManager(['sqlite']);
        $application->instance('db.transactions', $owned);

        (new DatabaseTransactionScope($application, ['sqlite']))->close();

        // Cleanup may never unbind a manager this scope did not install.
        Assert::true($application->bound('db.transactions'));
        Assert::same($application->make('db.transactions'), $owned);
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
