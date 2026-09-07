<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait RunsMigrations
{
    use RefreshDatabase;
}

final class ComposedTest extends TestCase
{
    use RunsMigrations;

    public function test_store(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait RunsMigrations
{
    use RefreshDatabase;
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): framework database strategy Illuminate\Foundation\Testing\RefreshDatabase arrives through project trait Tests\Feature\RunsMigrations and is not converted automatically - migrate it manually */
final class ComposedTest extends TestCase
{
    use RunsMigrations;

    public function test_store(): void {}
}
