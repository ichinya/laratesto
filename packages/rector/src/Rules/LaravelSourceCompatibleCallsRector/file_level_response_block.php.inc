<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class SafeResponseInMixedFileTest extends LaravelTestCase
{
    private TestResponse $pending;

    public function response(): TestResponse
    {
        return $this->get('/safe');
    }

    public function hold(TestResponse $response): void
    {
        $this->pending = $response;
    }
}

final class UnsafeResponseInMixedFileTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/unsafe');
        $response->assertDownload();

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): the file-wide TestResponse to Laratesto\Testing\LaravelResponse swap was blocked by sibling class Tests\Feature\UnsafeResponseInMixedFileTest; migrate TestResponse types to Laratesto\Testing\LaravelResponse manually */
final class SafeResponseInMixedFileTest extends LaravelTestCase
{
    private TestResponse $pending;

    public function response(): TestResponse
    {
        return $this->get('/safe');
    }

    public function hold(TestResponse $response): void
    {
        $this->pending = $response;
    }
}

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): TestResponse::assertDownload() is outside the supported response matrix */
final class UnsafeResponseInMixedFileTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/unsafe');
        $response->assertDownload();

        return $response;
    }
}
