<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laratesto\Attribute\DatabaseTransactions;
use Laratesto\Attribute\RefreshDatabase;
use Laratesto\Pipeline\Internal\DatabaseTransactionScope;
use Laratesto\Testing\InteractsWithLaravel;
use Testo\Assert;

/**
 * Stacked database attributes and mid-test disconnects must clean up without
 * corrupting an outer transaction scope or the RefreshDatabase migrated flag.
 */
final class DatabaseTransactionCleanupTest
{
    use InteractsWithLaravel;

    #[RefreshDatabase]
    #[DatabaseTransactions]
    public function testStackedAttributesOpenTwoOwnedTransactionLevels(): void
    {
        Assert::same($this->make('db')->transactionLevel(), 2);
        Assert::true($this->app()->bound('db.transactions'));

        DB::table('things')->insert(['name' => 'stacked']);
        Assert::same(DB::table('things')->count(), 1);
    }

    #[RefreshDatabase]
    public function testStackedCleanupKeepsTheMigratedStateAndRollsEverythingBack(): void
    {
        // The stacked teardown must not spuriously clear the migrated flag.
        Assert::true(RefreshDatabaseState::$migrated);
        Assert::true(Schema::hasTable('things'));
        Assert::same(DB::table('things')->count(), 0);
    }

    #[RefreshDatabase(connections: ['sqlite', 'secondary'])]
    #[DatabaseTransactions(connections: ['sqlite', 'secondary'])]
    public function testStackedAttributesNestEverySelectedConnection(): void
    {
        $database = $this->make('db');

        foreach (['sqlite', 'secondary'] as $name) {
            Assert::same($database->connection($name)->transactionLevel(), 2);
            $database->connection($name)->table('things')->insert(['name' => $name]);
        }
    }

    #[RefreshDatabase(connections: ['sqlite', 'secondary'])]
    public function testStackedMultiConnectionCleanupRestoresEveryConnection(): void
    {
        Assert::true(RefreshDatabaseState::$migrated);

        $database = $this->make('db');

        foreach (['sqlite', 'secondary'] as $name) {
            Assert::same($database->connection($name)->table('things')->count(), 0);
        }
    }

    #[RefreshDatabase]
    public function testRefreshDatabaseCleanupSurvivesAMidTestDisconnect(): void
    {
        Assert::true(Schema::hasTable('things'));

        DB::disconnect();

        // Disconnecting nulls the PDO and resets the depth counter, so the
        // cleanup must skip the connection instead of failing on it.
        Assert::same($this->make('db')->connection('sqlite')->transactionLevel(), 0);
    }

    #[RefreshDatabase]
    public function testDatabaseRecoversAfterAMidTestDisconnect(): void
    {
        // A null PDO must neither clear the migrated flag nor lose the schema.
        Assert::true(RefreshDatabaseState::$migrated);
        Assert::true(Schema::hasTable('things'));
        Assert::same(DB::table('things')->count(), 0);
    }

    #[DatabaseTransactions(connections: ['secondary'])]
    public function testDatabaseTransactionsCleanupSurvivesAMidTestDisconnect(): void
    {
        $connection = $this->make('db')->connection('secondary');

        Assert::true($connection->getSchemaBuilder()->hasTable('things'));

        $connection->disconnect();
    }

    #[DatabaseTransactions(connections: ['secondary'])]
    public function testInMemoryCacheSurvivesAMidTestDisconnect(): void
    {
        $connection = $this->make('db')->connection('secondary');

        // The cached in-memory PDO must not be overwritten by a null PDO.
        Assert::true($connection->getSchemaBuilder()->hasTable('things'));
        Assert::same($connection->table('things')->count(), 0);
    }

    public function testUnresolvedSingletonStaysLazyAcrossAScope(): void
    {
        $application = $this->app();
        $resolutions = 0;
        $application->singleton('db.transactions', function () use (&$resolutions) {
            $resolutions++;

            return new DatabaseTransactionsManager(['sqlite']);
        });

        $scope = new DatabaseTransactionScope($application, ['sqlite']);
        $scope->begin();

        // Snapshot must classify by container state, never by resolving.
        Assert::same($resolutions, 0);

        $scope->close();

        // The singleton binding survives unresolved: nothing froze or probed it.
        Assert::true($application->bound('db.transactions'));
        Assert::same($resolutions, 0);

        // The first resolution happens only now, through the intact binding.
        Assert::instanceOf($application->make('db.transactions'), DatabaseTransactionsManager::class);
        Assert::same($resolutions, 1);
    }
}
