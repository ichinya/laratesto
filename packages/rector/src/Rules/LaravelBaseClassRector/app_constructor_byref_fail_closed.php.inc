<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class HoldsContainer
{
    public function __construct(&$container)
    {
    }
}

final class AppNewByRefTest extends TestCase
{
    public function test_constructs(): void
    {
        $holder = new HoldsContainer($this->app);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class HoldsContainer
{
    public function __construct(&$container)
    {
    }
}

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): $this->app is used in a write context (assignment/isset/unset/reference) that cannot become an app() method call */
final class AppNewByRefTest extends TestCase
{
    public function test_constructs(): void
    {
        $holder = new HoldsContainer($this->app);
    }
}
