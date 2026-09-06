<?php

namespace Other;

function takeRef($container): void {}

namespace Tests\Feature;

function takeRef(&$container): void {}

final class NamespacedReferenceTest extends \Illuminate\Foundation\Testing\TestCase
{
    public function test_reference(): void
    {
        takeRef($this->app);
    }
}
-----
<?php

namespace Other;

function takeRef($container): void {}

namespace Tests\Feature;

function takeRef(&$container): void {}

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): $this->app is used in a write context (assignment/isset/unset/reference) that cannot become an app() method call */
final class NamespacedReferenceTest extends \Illuminate\Foundation\Testing\TestCase
{
    public function test_reference(): void
    {
        takeRef($this->app);
    }
}
