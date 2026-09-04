<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class AssertSeeMatrixTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertSee('visible');
        $response->assertSee(['shipped', 'delivered']);
        $response->assertSee('Raw & <b>', false);
        $response->assertSee(['Raw & <b>', 'gone'], false);
        $response->assertSee('shipped')->assertOk();
        $fluent = $response->assertSee(['a', 'b']);
        $fluent->assertStatus(201);

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class AssertSeeMatrixTest extends LaravelTestCase
{
    public function response(): \Laratesto\Testing\LaravelResponse
    {
        $response = $this->get('/users');
        $response->assertSee('visible');
        $response->assertSee(['shipped', 'delivered']);
        $response->assertSee('Raw & <b>', false);
        $response->assertSee(['Raw & <b>', 'gone'], false);
        $response->assertSee('shipped')->assertOk();
        $fluent = $response->assertSee(['a', 'b']);
        $fluent->assertStatus(201);

        return $response;
    }
}
