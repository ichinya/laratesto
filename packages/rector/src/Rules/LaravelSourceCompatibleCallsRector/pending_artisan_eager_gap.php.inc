<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class EagerPendingArtisanTest extends LaravelTestCase
{
    public function test_side_effects_land_before_first_use(): void
    {
        $pending = $this->artisan('parity:report');
        $this->travel(5);

        $pending->assertExitCode(0);
    }

    public function test_immediate_use_stays_clean(): void
    {
        $pending = $this->artisan('parity:report');
        $pending->assertExitCode(0);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=ARTISAN_INTERACTION_UNSUPPORTED, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): $pending retains a Pending Artisan command across statements or control flow; Laravel executes it on release, while Laratesto executes it eagerly */
final class EagerPendingArtisanTest extends LaravelTestCase
{
    public function test_side_effects_land_before_first_use(): void
    {
        $pending = $this->artisan('parity:report');
        $this->travel(5);

        $pending->assertExitCode(0);
    }

    public function test_immediate_use_stays_clean(): void
    {
        $pending = $this->artisan('parity:report');
        $pending->assertExitCode(0);
    }
}
