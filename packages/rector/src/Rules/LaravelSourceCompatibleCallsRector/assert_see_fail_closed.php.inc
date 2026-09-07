<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class AssertSeeFailClosedTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertSee();
        $response->assertSee($needles);
        $response->assertSee('shipped', 'yes');

        $fluent = $response->assertSee('shipped');
        $fluent->assertDownload();

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): assertSee() expects 1..2 common-path arguments; got 0; assertSee() argument 1 is not a statically supported string-or-array; assertSee() argument 2 is not a statically supported bool; TestResponse::assertDownload() is outside the supported response matrix */
final class AssertSeeFailClosedTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertSee();
        $response->assertSee($needles);
        $response->assertSee('shipped', 'yes');

        $fluent = $response->assertSee('shipped');
        $fluent->assertDownload();

        return $response;
    }
}
