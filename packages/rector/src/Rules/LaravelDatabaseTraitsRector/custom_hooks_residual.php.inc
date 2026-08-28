<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class CustomHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        \unlink(__DIR__ . '/storage.sqlite');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector): RefreshDatabase has custom hook methods — migrate manually */
final class CustomHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        \unlink(__DIR__ . '/storage.sqlite');
    }
}
