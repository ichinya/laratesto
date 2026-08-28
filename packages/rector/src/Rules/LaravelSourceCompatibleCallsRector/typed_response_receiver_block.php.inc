<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class TypedResponseReceiverTest extends LaravelTestCase
{
    private TestResponse $cachedResponse;

    private function response(): TestResponse
    {
        return $this->get('/users');
    }

    public function test_unsupported_typed_receivers(): void
    {
        $this->cachedResponse->assertDownload();
        $this->response()->assertStreamedContent('body');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): TestResponse::assertDownload() is outside the supported response matrix; TestResponse::assertStreamedContent() is outside the supported response matrix */
final class TypedResponseReceiverTest extends LaravelTestCase
{
    private TestResponse $cachedResponse;

    private function response(): TestResponse
    {
        return $this->get('/users');
    }

    public function test_unsupported_typed_receivers(): void
    {
        $this->cachedResponse->assertDownload();
        $this->response()->assertStreamedContent('body');
    }
}
