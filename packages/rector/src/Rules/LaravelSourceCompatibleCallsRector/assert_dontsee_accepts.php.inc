<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class AssertDontSeeMatrixTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertDontSee('hidden');
        $response->assertDontSee(['gone', 'missing']);
        $response->assertDontSee('Raw & <b>', false);
        $response->assertDontSee('gone')->assertOk();
        $fluent = $response->assertDontSee(['a', 'b']);
        $fluent->assertStatus(201);

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class AssertDontSeeMatrixTest extends LaravelTestCase
{
    public function response(): \Laratesto\Testing\LaravelResponse
    {
        $response = $this->get('/users');
        $response->assertDontSee('hidden');
        $response->assertDontSee(['gone', 'missing']);
        $response->assertDontSee('Raw & <b>', false);
        $response->assertDontSee('gone')->assertOk();
        $fluent = $response->assertDontSee(['a', 'b']);
        $fluent->assertStatus(201);

        return $response;
    }
}
