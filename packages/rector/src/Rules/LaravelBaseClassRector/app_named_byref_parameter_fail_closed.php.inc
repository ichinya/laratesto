<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

function takeRef(&$container): void
{
}

final class AppNamedByRefParamTest extends TestCase
{
    public function test_passes_named(): void
    {
        takeRef(container: $this->app);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

function takeRef(&$container): void
{
}

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): $this->app is used in a write context (assignment/isset/unset/reference) that cannot become an app() method call */
final class AppNamedByRefParamTest extends TestCase
{
    public function test_passes_named(): void
    {
        takeRef(container: $this->app);
    }
}
