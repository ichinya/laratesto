<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class ApiTest extends LaravelTestCase
{
    public function test_creates_and_reads_user(): void
    {
        $response = $this->postJson('/api/users', ['name' => 'A', 'email' => 'a@b.c']);
        $response->assertStatus(201);
        $response->assertJsonPath('name', 'A');

        $this->withHeaders(['X-Request-Id' => '42'])
            ->get('/api/users')
            ->assertOk()
            ->assertJson(['data' => [['name' => 'A']]]);

        $this->deleteJson('/api/users/1')->assertStatus(200);
    }

    public function test_auth_and_database_helpers(): void
    {
        $user = new \App\Models\TestUser(['id' => 1]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => 1]);
        $this->assertDatabaseMissing('users', ['id' => 99]);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }
}
