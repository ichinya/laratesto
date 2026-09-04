<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class ResponseMatrixNewAssertsFailClosedTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertJsonStructure();
        $response->assertJsonStructure(['id'], ['id' => 1]);
        $response->assertContent($body);
        $response->assertSessionHasErrors('email', format: null);
        $response->assertSessionHasAll(['a', 'b']);
        $response->assertJsonMissingPath($path);
        $response->assertRedirectToRoute('home');

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): assertJsonStructure() expects 1..1 common-path arguments; got 0; assertJsonStructure() expects 1..1 common-path arguments; got 2; assertContent() argument 1 is not a statically supported string; assertSessionHasErrors() uses named or unpacked arguments outside the automatic matrix; TestResponse::assertSessionHasAll() is outside the supported response matrix; assertJsonMissingPath() argument 1 is not a statically supported string; TestResponse::assertRedirectToRoute() is outside the supported response matrix */
final class ResponseMatrixNewAssertsFailClosedTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertJsonStructure();
        $response->assertJsonStructure(['id'], ['id' => 1]);
        $response->assertContent($body);
        $response->assertSessionHasErrors('email', format: null);
        $response->assertSessionHasAll(['a', 'b']);
        $response->assertJsonMissingPath($path);
        $response->assertRedirectToRoute('home');

        return $response;
    }
}
