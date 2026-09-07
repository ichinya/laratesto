<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache', ['tenant' => 1]);
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
        $this->travel(5);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): $this->app->make() is automatic only with one argument */
abstract class TestCase extends FoundationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache', ['tenant' => 1]);
    }

    public function acmeHelper(): string
    {
        return 'acme';
    }
}

namespace Tests\Feature;

use Tests\TestCase;

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): project base Tests\TestCase carries unsupported constructs: a blocking HTTP_UNSUPPORTED_SIGNATURE residual marker is already present; $this->app->make() is automatic only with one argument */
final class SafeDescendantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_safe_descendant(): void
    {
        $this->travel(5);
    }
}
