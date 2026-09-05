<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class CustomMigrateDatabasesTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate', ['--step']);
    }
}

final class CustomFreshArgumentsTest extends TestCase
{
    use DatabaseMigrations;

    protected function migrateFreshUsing(): array
    {
        return ['--drop-views' => true, '--force' => true];
    }
}

final class CustomTableListingTest extends TestCase
{
    use DatabaseTruncation;

    protected function getAllTablesForConnection(\Illuminate\Database\ConnectionInterface $connection, ?string $name): array
    {
        return ['orders'];
    }
}

final class CustomTransactedConnectionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }
}

final class CaseVariantHookTest extends TestCase
{
    use RefreshDatabase;

    protected function BEFORERefreshingDatabase(): void
    {
        \unlink(__DIR__ . '/storage.sqlite');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database override migrateDatabases() requires manual migration */
final class CustomMigrateDatabasesTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate', ['--step']);
    }
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database override migrateFreshUsing() requires manual migration */
final class CustomFreshArgumentsTest extends TestCase
{
    use DatabaseMigrations;

    protected function migrateFreshUsing(): array
    {
        return ['--drop-views' => true, '--force' => true];
    }
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database override getAllTablesForConnection() requires manual migration */
final class CustomTableListingTest extends TestCase
{
    use DatabaseTruncation;

    protected function getAllTablesForConnection(\Illuminate\Database\ConnectionInterface $connection, ?string $name): array
    {
        return ['orders'];
    }
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database override connectionsToTransact() requires manual migration */
final class CustomTransactedConnectionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database override BEFORERefreshingDatabase() requires manual migration */
final class CaseVariantHookTest extends TestCase
{
    use RefreshDatabase;

    protected function BEFORERefreshingDatabase(): void
    {
        \unlink(__DIR__ . '/storage.sqlite');
    }
}
