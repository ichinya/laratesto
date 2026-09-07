<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class SupportedResponseTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertStatus(200)->assertJson(['data' => []]);
        $response->assertJsonPath('data', static fn (array $data): bool => $data === []);
        $response->assertHeader('X-Mode', 'Exact');

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class SupportedResponseTest extends LaravelTestCase
{
    public function response(): \Laratesto\Testing\LaravelResponse
    {
        $response = $this->get('/users');
        $response->assertStatus(200)->assertJson(['data' => []]);
        $response->assertJsonPath('data', static fn (array $data): bool => $data === []);
        $response->assertHeader('X-Mode', 'Exact');

        return $response;
    }
}
