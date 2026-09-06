<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class LazyComposedTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_store(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): framework database strategy Illuminate\Foundation\Testing\RefreshDatabase arrives through project trait Illuminate\Foundation\Testing\LazilyRefreshDatabase and is not converted automatically - migrate it manually */
final class LazyComposedTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_store(): void {}
}
