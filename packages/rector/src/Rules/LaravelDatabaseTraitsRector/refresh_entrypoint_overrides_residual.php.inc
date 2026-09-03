<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class CustomRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function refreshDatabase(): void
    {
        $this->artisan('migrate', ['--path' => 'database/migrations/custom']);
    }
}

final class InMemoryRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function refreshInMemoryDatabase(): void
    {
        $this->artisan('migrate', ['--path' => 'database/migrations/memory']);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database override refreshDatabase() requires manual migration */
final class CustomRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function refreshDatabase(): void
    {
        $this->artisan('migrate', ['--path' => 'database/migrations/custom']);
    }
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database override refreshInMemoryDatabase() requires manual migration */
final class InMemoryRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function refreshInMemoryDatabase(): void
    {
        $this->artisan('migrate', ['--path' => 'database/migrations/memory']);
    }
}
