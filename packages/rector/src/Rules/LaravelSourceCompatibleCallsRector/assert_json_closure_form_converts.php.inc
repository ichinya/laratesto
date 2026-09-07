<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\AssertableJson;
use Laratesto\Testing\LaravelTestCase;

final class AssertJsonClosureTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/api/users');

        $response->assertJson(['data' => ['id' => 1]]);
        $response->assertJson(fn (AssertableJson $json) => $json->where('data.id', 1));
        $response->assertJson(function (AssertableJson $json): void {
            $json->has('data');
        });

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\AssertableJson;
use Laratesto\Testing\LaravelTestCase;

final class AssertJsonClosureTest extends LaravelTestCase
{
    public function response(): \Laratesto\Testing\LaravelResponse
    {
        $response = $this->get('/api/users');

        $response->assertJson(['data' => ['id' => 1]]);
        $response->assertJson(fn (AssertableJson $json) => $json->where('data.id', 1));
        $response->assertJson(function (AssertableJson $json): void {
            $json->has('data');
        });

        return $response;
    }
}
