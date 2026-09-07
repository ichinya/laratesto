<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class DynamicResponseHelperFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $response = $this->get("/");
        $assertOk = "assertDownload";
        $response->{$assertOk}();
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): dynamic Laravel helper method cannot be classified */
final class DynamicResponseHelperFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $response = $this->get("/");
        $assertOk = "assertDownload";
        $response->{$assertOk}();
    }
}
