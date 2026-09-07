<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class CallNamedUnpackFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $this->call(method: "GET", uri: "/");
        $arguments = ["/"];
        $this->call("GET", ...$arguments);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): call() uses named or unpacked arguments outside the automatic matrix */
final class CallNamedUnpackFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $this->call(method: "GET", uri: "/");
        $arguments = ["/"];
        $this->call("GET", ...$arguments);
    }
}
