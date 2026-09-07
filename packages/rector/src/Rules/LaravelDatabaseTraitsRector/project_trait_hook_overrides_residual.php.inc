<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait LogsBeforeRefresh
{
    protected function beforeRefreshingDatabase(): void
    {
        \file_put_contents(__DIR__ . '/refresh.log', 'before', FILE_APPEND);
    }
}

trait DeeperLogsAfterRefresh
{
    protected function afterRefreshingDatabase(): void
    {
        \file_put_contents(__DIR__ . '/refresh.log', 'after', FILE_APPEND);
    }
}

trait ComposedLogHooks
{
    use DeeperLogsAfterRefresh;
}

trait OwnTableListing
{
    protected function truncateDatabaseTables(): void
    {
        $this->artisan('db:truncate');
    }
}

final class AdaptedTraitHookTest extends TestCase
{
    use RefreshDatabase;
    use LogsBeforeRefresh {
        LogsBeforeRefresh::beforeRefreshingDatabase insteadof RefreshDatabase;
    }

    public function test_ok(): void {}
}

final class ComposedTraitHookTest extends TestCase
{
    use RefreshDatabase;
    use ComposedLogHooks {
        ComposedLogHooks::afterRefreshingDatabase insteadof RefreshDatabase;
    }

    public function test_ok(): void {}
}

final class CollidingTraitHookTest extends TestCase
{
    use DatabaseTruncation;
    use OwnTableListing;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait LogsBeforeRefresh
{
    protected function beforeRefreshingDatabase(): void
    {
        \file_put_contents(__DIR__ . '/refresh.log', 'before', FILE_APPEND);
    }
}

trait DeeperLogsAfterRefresh
{
    protected function afterRefreshingDatabase(): void
    {
        \file_put_contents(__DIR__ . '/refresh.log', 'after', FILE_APPEND);
    }
}

trait ComposedLogHooks
{
    use DeeperLogsAfterRefresh;
}

trait OwnTableListing
{
    protected function truncateDatabaseTables(): void
    {
        $this->artisan('db:truncate');
    }
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database override beforeRefreshingDatabase() on trait Tests\Feature\LogsBeforeRefresh requires manual migration */
final class AdaptedTraitHookTest extends TestCase
{
    use RefreshDatabase;
    use LogsBeforeRefresh {
        LogsBeforeRefresh::beforeRefreshingDatabase insteadof RefreshDatabase;
    }

    public function test_ok(): void {}
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database override afterRefreshingDatabase() on trait Tests\Feature\DeeperLogsAfterRefresh requires manual migration */
final class ComposedTraitHookTest extends TestCase
{
    use RefreshDatabase;
    use ComposedLogHooks {
        ComposedLogHooks::afterRefreshingDatabase insteadof RefreshDatabase;
    }

    public function test_ok(): void {}
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database override truncateDatabaseTables() on trait Tests\Feature\OwnTableListing requires manual migration */
final class CollidingTraitHookTest extends TestCase
{
    use DatabaseTruncation;
    use OwnTableListing;

    public function test_ok(): void {}
}
