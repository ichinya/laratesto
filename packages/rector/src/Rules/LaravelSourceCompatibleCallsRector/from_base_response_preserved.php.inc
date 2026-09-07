<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

final class FromBaseResponseTest extends TestCase
{
    public function test_factory(): void
    {
        $response = TestResponse::fromBaseResponse(new Response('body'));

        $response->assertOk();
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): TestResponse::fromBaseResponse() has no Laratesto static equivalent; preserve the Laravel response type and migrate manually */
final class FromBaseResponseTest extends TestCase
{
    public function test_factory(): void
    {
        $response = TestResponse::fromBaseResponse(new Response('body'));

        $response->assertOk();
    }
}
