<?php

declare(strict_types=1);

namespace Tests\Feature\Parity;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ticket 07 supported corpus: common HTTP/request/response/Artisan signatures that
 * must survive unchanged (source-compatible) plus the supported TestResponse type
 * rewrite after a green preflight.
 */
final class HttpAndArtisanTest extends TestCase
{
    public function test_json_request_and_response_assertions(): void
    {
        $response = $this->postJson('/parity/users', ['name' => 'A', 'email' => 'a@b.c']);

        $response->assertStatus(201);
        $response->assertJsonPath('name', 'A');
        $response->assertJson(['created' => true]);
        $response->assertHeader('Content-Type');
    }

    public function test_header_and_session_helpers_stay_source_compatible(): void
    {
        $this->withHeaders(['X-Parity' => '1'])
            ->withSession(['parity' => 'kept'])
            ->get('/parity/session')
            ->assertOk()
            ->assertJson(['parity' => 'kept']);
    }

    public function test_artisan_pending_command_chain(): void
    {
        $this->artisan('parity:ping', ['--target' => 'world'])
            ->expectsOutput('pong world')
            ->assertExitCode(0);
    }

    public function test_response_type_hint_rewrites_after_preflight(): TestResponse
    {
        return $this->get('/parity/ping');
    }
}
