<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class SafeResponseInMixedFileTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        return $this->get('/safe');
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

final class SafeResponseInMixedFileTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        return $this->get('/safe');
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
