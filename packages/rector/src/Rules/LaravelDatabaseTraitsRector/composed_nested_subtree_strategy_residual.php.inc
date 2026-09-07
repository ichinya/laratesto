<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait HidesStrategyInLocalClass
{
    public function boot(): void
    {
        if (false) {
            $local = new class
            {
                use RefreshDatabase;
            };

            $local->refreshDatabase();
        }
    }
}

final class NestedSubtreeTest extends TestCase
{
    use HidesStrategyInLocalClass;

    public function test_store(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait HidesStrategyInLocalClass
{
    public function boot(): void
    {
        if (false) {
            $local = new class
            {
                use RefreshDatabase;
            };

            $local->refreshDatabase();
        }
    }
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): framework database strategy Illuminate\Foundation\Testing\RefreshDatabase arrives through project trait Tests\Feature\HidesStrategyInLocalClass and is not converted automatically - migrate it manually */
final class NestedSubtreeTest extends TestCase
{
    use HidesStrategyInLocalClass;

    public function test_store(): void {}
}
