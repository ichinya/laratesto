<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class CallDynamicShapeFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $method = "GET";
        $this->call($method, "/");
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): call() argument 1 is not a statically supported string */
final class CallDynamicShapeFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $method = "GET";
        $this->call($method, "/");
    }
}
