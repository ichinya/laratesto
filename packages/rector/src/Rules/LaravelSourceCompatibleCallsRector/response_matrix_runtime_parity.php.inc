<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class RuntimeResponseMatrixTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertSee('Users');
        $response->assertSee('Raw & <b>', false);
        $response->assertExactJson(['a' => 1, 'b' => ['c' => 2]]);
        $response->assertHeaderMissing('X-Missing');
        $response->assertCreated();
        $response->assertBadRequest();
        $response->assertUnauthorized();
        $response->assertForbidden();
        $response->assertNotFound();
        $response->assertUnprocessable();

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class RuntimeResponseMatrixTest extends LaravelTestCase
{
    public function response(): \Laratesto\Testing\LaravelResponse
    {
        $response = $this->get('/users');
        $response->assertSee('Users');
        $response->assertSee('Raw & <b>', false);
        $response->assertExactJson(['a' => 1, 'b' => ['c' => 2]]);
        $response->assertHeaderMissing('X-Missing');
        $response->assertCreated();
        $response->assertBadRequest();
        $response->assertUnauthorized();
        $response->assertForbidden();
        $response->assertNotFound();
        $response->assertUnprocessable();

        return $response;
    }
}
