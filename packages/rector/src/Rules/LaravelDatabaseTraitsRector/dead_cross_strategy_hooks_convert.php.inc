<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class TruncationWithRefreshHooksTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [null];

    protected function beforeRefreshingDatabase(): void
    {
        \unlink(__DIR__ . '/storage.sqlite');
    }

    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }
}

final class MigrationsWithTruncationHooksTest extends TestCase
{
    use DatabaseMigrations;

    protected function refreshDatabase(): void
    {
        $this->artisan('migrate', ['--path' => 'database/migrations/custom']);
    }

    protected function connectionsToTruncate(): array
    {
        return ['archive'];
    }

    protected function tablesToTruncate(): array
    {
        return ['orders'];
    }

    protected function exceptTables(): array
    {
        return ['sessions'];
    }
}

final class TransactionsWithRefreshHooksTest extends TestCase
{
    use DatabaseTransactions;

    protected function beforeTruncatingDatabase(): void
    {
        \cache()->flush();
    }

    protected function migrateFreshUsing(): array
    {
        return ['--drop-views' => true];
    }
}

final class StaleInMemoryEntrypointTest extends TestCase
{
    use RefreshDatabase;

    protected function refreshInMemoryDatabase(): void
    {
        $this->artisan('migrate', ['--path' => 'database/migrations/memory']);
    }
}

trait LogsBeforeRefreshHooks
{
    protected function beforeRefreshingDatabase(): void
    {
        \unlink(__DIR__ . '/storage.sqlite');
    }
}

final class TransactionsWithDeadTraitHookTest extends TestCase
{
    use DatabaseTransactions;
    use LogsBeforeRefreshHooks;
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

#[\Laratesto\Attribute\DatabaseTruncation]
final class TruncationWithRefreshHooksTest extends TestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        \unlink(__DIR__ . '/storage.sqlite');
    }

    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }
}

#[\Laratesto\Attribute\DatabaseMigrations]
final class MigrationsWithTruncationHooksTest extends TestCase
{
    protected function refreshDatabase(): void
    {
        $this->artisan('migrate', ['--path' => 'database/migrations/custom']);
    }

    protected function connectionsToTruncate(): array
    {
        return ['archive'];
    }

    protected function tablesToTruncate(): array
    {
        return ['orders'];
    }

    protected function exceptTables(): array
    {
        return ['sessions'];
    }
}

#[\Laratesto\Attribute\DatabaseTransactions]
final class TransactionsWithRefreshHooksTest extends TestCase
{
    protected function beforeTruncatingDatabase(): void
    {
        \cache()->flush();
    }

    protected function migrateFreshUsing(): array
    {
        return ['--drop-views' => true];
    }
}

#[\Laratesto\Attribute\RefreshDatabase]
final class StaleInMemoryEntrypointTest extends TestCase
{
    protected function refreshInMemoryDatabase(): void
    {
        $this->artisan('migrate', ['--path' => 'database/migrations/memory']);
    }
}

trait LogsBeforeRefreshHooks
{
    protected function beforeRefreshingDatabase(): void
    {
        \unlink(__DIR__ . '/storage.sqlite');
    }
}

#[\Laratesto\Attribute\DatabaseTransactions]
final class TransactionsWithDeadTraitHookTest extends TestCase
{
    use LogsBeforeRefreshHooks;
}
