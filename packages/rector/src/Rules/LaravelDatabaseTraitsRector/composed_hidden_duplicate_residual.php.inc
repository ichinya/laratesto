<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait ReusableMigrations
{
    use RefreshDatabase;
}

final class DuplicateHiddenTest extends TestCase
{
    use RefreshDatabase;
    use ReusableMigrations;

    public function test_store(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait ReusableMigrations
{
    use RefreshDatabase;
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): framework database strategy Illuminate\Foundation\Testing\RefreshDatabase arrives through project trait Tests\Feature\ReusableMigrations and is not converted automatically - migrate it manually */
final class DuplicateHiddenTest extends TestCase
{
    use RefreshDatabase;
    use ReusableMigrations;

    public function test_store(): void {}
}
