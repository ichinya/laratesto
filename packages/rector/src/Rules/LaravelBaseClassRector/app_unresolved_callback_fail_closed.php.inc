<?php

namespace Tests\Feature;

final class AppCallbackTest extends \Illuminate\Foundation\Testing\TestCase
{
    public function test_reference(): void
    {
        $callback = function (&$container): void { $container = null; };
        $callback($this->app);
    }
}
-----
<?php

namespace Tests\Feature;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): $this->app is passed to a call whose by-value parameter signature cannot be proven */
final class AppCallbackTest extends \Illuminate\Foundation\Testing\TestCase
{
    public function test_reference(): void
    {
        $callback = function (&$container): void { $container = null; };
        $callback($this->app);
    }
}
