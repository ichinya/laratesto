<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Testo\Assert;
use Laratesto\Testing\LaravelTestCase;

final class HttpRequestsTest extends LaravelTestCase
{
    public function testGetRequestPassesThroughTheHttpKernel(): void
    {
        $this->get('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['laravel' => 'ok']);
    }

    public function testPostJsonSendsAnEncodedPayload(): void
    {
        $response = $this->postJson('/api/echo', ['message' => 'hello']);

        $response->assertOk();

        Assert::same($response->json(), [
            'received' => 'hello',
            'content_type' => 'application/json',
        ]);
    }

    public function testPostSendsFormParameters(): void
    {
        $response = $this->post('/api/echo', ['message' => 'form-data']);

        $response->assertOk();
        Assert::same($response->json()['received'], 'form-data');
    }

    public function testUnknownRouteReturnsNotFound(): void
    {
        $this->get('/api/nope')->assertStatus(404);
    }

    public function testArtisanCommandsAreExecutable(): void
    {
        $this->artisan('route:list')->assertExitCode(0);
    }
}
