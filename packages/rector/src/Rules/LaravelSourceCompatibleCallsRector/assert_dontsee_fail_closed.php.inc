<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class AssertDontSeeFailClosedTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertDontSee();
        $response->assertDontSee($needles);
        $response->assertDontSee('gone', 'yes');

        $fluent = $response->assertDontSee('gone');
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

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): assertDontSee() expects 1..2 common-path arguments; got 0; assertDontSee() argument 1 is not a statically supported string-or-array; assertDontSee() argument 2 is not a statically supported bool; TestResponse::assertDownload() is outside the supported response matrix */
final class AssertDontSeeFailClosedTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertDontSee();
        $response->assertDontSee($needles);
        $response->assertDontSee('gone', 'yes');

        $fluent = $response->assertDontSee('gone');
        $fluent->assertDownload();

        return $response;
    }
}
