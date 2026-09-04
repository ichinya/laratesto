<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class StatusShortcutMatrixTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertFound();
        $response->assertMethodNotAllowed();
        $response->assertConflict();
        $response->assertGone();
        $response->assertInternalServerError();
        $response->assertTooManyRequests();
        $response->assertServiceUnavailable();
        $response->assertFound()->assertTooManyRequests();
        $fluent = $response->assertServiceUnavailable();
        $fluent->assertGone();

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class StatusShortcutMatrixTest extends LaravelTestCase
{
    public function response(): \Laratesto\Testing\LaravelResponse
    {
        $response = $this->get('/users');
        $response->assertFound();
        $response->assertMethodNotAllowed();
        $response->assertConflict();
        $response->assertGone();
        $response->assertInternalServerError();
        $response->assertTooManyRequests();
        $response->assertServiceUnavailable();
        $response->assertFound()->assertTooManyRequests();
        $fluent = $response->assertServiceUnavailable();
        $fluent->assertGone();

        return $response;
    }
}
