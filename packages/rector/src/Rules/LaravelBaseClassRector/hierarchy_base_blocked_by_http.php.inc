<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    public function importPending(): void
    {
        $this->artisan('acme:import')->expectsQuestion('Force?', 'yes');
    }

    public function acmeHelper(): string
    {
        return 'acme';
    }
}

namespace Tests\Feature;

use Tests\TestCase;

final class SafeDescendantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_safe_descendant(): void
    {
        $this->assertSame('acme', $this->acmeHelper());
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

/* laratesto-residual(code=ARTISAN_INTERACTION_UNSUPPORTED, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): expectsQuestion() is an interactive Pending Artisan API */
abstract class TestCase extends FoundationTestCase
{
    public function importPending(): void
    {
        $this->artisan('acme:import')->expectsQuestion('Force?', 'yes');
    }

    public function acmeHelper(): string
    {
        return 'acme';
    }
}

namespace Tests\Feature;

use Tests\TestCase;

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): project base Tests\TestCase carries unsupported constructs: a blocking ARTISAN_INTERACTION_UNSUPPORTED residual marker is already present; expectsQuestion() is an interactive Pending Artisan API */
final class SafeDescendantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_safe_descendant(): void
    {
        $this->assertSame('acme', $this->acmeHelper());
    }
}
