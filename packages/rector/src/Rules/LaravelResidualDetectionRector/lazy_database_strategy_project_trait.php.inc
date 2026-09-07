<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Some\Unloaded\LazySetup;

trait LazyDatabaseSetup
{
    use LazilyRefreshDatabase;
}

final class ComposedLazyTest extends TestCase
{
    use LazyDatabaseSetup;

    public function test_database(): void
    {
        $this->assertDatabaseHas('things', ['name' => 'seeded']);
    }
}

final class UnresolvableTraitTest extends TestCase
{
    use LazySetup;

    public function test_database(): void
    {
        $this->assertDatabaseHas('things', ['name' => 'seeded']);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Some\Unloaded\LazySetup;

trait LazyDatabaseSetup
{
    use LazilyRefreshDatabase;
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual): project trait Tests\Feature\LazyDatabaseSetup composes Illuminate\Foundation\Testing\LazilyRefreshDatabase — the lazy database refresh strategy has no automatic conversion; migrate manually */
final class ComposedLazyTest extends TestCase
{
    use LazyDatabaseSetup;

    public function test_database(): void
    {
        $this->assertDatabaseHas('things', ['name' => 'seeded']);
    }
}

final class UnresolvableTraitTest extends TestCase
{
    use LazySetup;

    public function test_database(): void
    {
        $this->assertDatabaseHas('things', ['name' => 'seeded']);
    }
}
