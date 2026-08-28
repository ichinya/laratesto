<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Uri;
use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class UnsupportedSignaturesTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->postJson('/users', [], [], JSON_THROW_ON_ERROR);
        $this->get(new Uri('/users'));
        $response->assertJsonFragment(['ok' => true]);
        $this->artisan('demo')->expectsQuestion('Name?', 'Ada');

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Uri;
use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): postJson() expects 1..3 common-path arguments; got 4; get() argument 1 is not a statically supported string */
/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): TestResponse::assertJsonFragment() is outside the supported response matrix */
/* laratesto-residual(code=ARTISAN_INTERACTION_UNSUPPORTED, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): expectsQuestion() is an interactive Pending Artisan API */
final class UnsupportedSignaturesTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->postJson('/users', [], [], JSON_THROW_ON_ERROR);
        $this->get(new Uri('/users'));
        $response->assertJsonFragment(['ok' => true]);
        $this->artisan('demo')->expectsQuestion('Name?', 'Ada');

        return $response;
    }
}
