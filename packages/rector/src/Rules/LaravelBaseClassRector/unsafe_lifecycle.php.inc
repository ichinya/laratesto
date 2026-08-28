<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class UnsafeLifecycleTest extends TestCase
{
    protected static function setUp(string $profile): void
    {
        parent::setUp();
    }

    public function test_unsafe(): void
    {
        $this->app->make('cache', ['tenant' => 1]);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): setUp() must be a concrete non-static zero-argument override */
/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): $this->app->make() is automatic only with one argument */
final class UnsafeLifecycleTest extends TestCase
{
    protected static function setUp(string $profile): void
    {
        parent::setUp();
    }

    public function test_unsafe(): void
    {
        $this->app->make('cache', ['tenant' => 1]);
    }
}
