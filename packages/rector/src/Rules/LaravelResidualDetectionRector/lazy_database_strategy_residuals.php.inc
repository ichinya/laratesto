<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase as LazyDb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class LazySetupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_database(): void
    {
        $this->assertDatabaseHas('things', ['name' => 'seeded']);
    }
}

final class AliasedLazySetupTest extends TestCase
{
    use LazyDb;

    public function test_database(): void
    {
        $this->assertDatabaseHas('things', ['name' => 'seeded']);
    }
}

final class EagerControlTest extends TestCase
{
    use RefreshDatabase;

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
use Illuminate\Foundation\Testing\LazilyRefreshDatabase as LazyDb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual): Illuminate\Foundation\Testing\LazilyRefreshDatabase trait — the lazy database refresh strategy has no automatic conversion; migrate manually */
final class LazySetupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_database(): void
    {
        $this->assertDatabaseHas('things', ['name' => 'seeded']);
    }
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual): Illuminate\Foundation\Testing\LazilyRefreshDatabase trait — the lazy database refresh strategy has no automatic conversion; migrate manually */
final class AliasedLazySetupTest extends TestCase
{
    use LazyDb;

    public function test_database(): void
    {
        $this->assertDatabaseHas('things', ['name' => 'seeded']);
    }
}

final class EagerControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_database(): void
    {
        $this->assertDatabaseHas('things', ['name' => 'seeded']);
    }
}
