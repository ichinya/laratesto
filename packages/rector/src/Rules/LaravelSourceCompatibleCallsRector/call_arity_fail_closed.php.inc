<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class CallArityFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $this->call("GET");
        $this->call("POST", "/", [], [], [], [], null, false);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): call() expects 2..7 common-path arguments; got 1; call() expects 2..7 common-path arguments; got 8 */
final class CallArityFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $this->call("GET");
        $this->call("POST", "/", [], [], [], [], null, false);
    }
}
