<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class SessionViewJsonMatrixTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/users');
        $response->assertContent('<html></html>');
        $response->assertSessionHas('status');
        $response->assertSessionHas('user.id', 1);
        $response->assertSessionHas(['status' => 'ok', 'partner']);
        $response->assertSessionMissing('expired');
        $response->assertSessionMissing(['expired', 'revoked']);
        $response->assertSessionHasErrors();
        $response->assertSessionHasErrors('email');
        $response->assertSessionHasErrors(['email', 'name']);
        $response->assertSessionHasErrors(['email' => 'required'], ':message', 'login');
        $response->assertJsonMissingPath('user.email');
        $response->assertJsonStructure(['id', 'user' => ['name']]);
        $response->assertJsonValidationErrors('email');
        $response->assertJsonValidationErrors(['email', 'name'], 'errors');
        $response->assertViewHas('user');
        $response->assertViewHas('user.name', 'Taylor');
        $response->assertViewHas(['user', 'post']);
        $response->assertContent('<html></html>')->assertSessionHas('status');
        $fluent = $response->assertViewHas('user');
        $fluent->assertJsonStructure(['id']);

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class SessionViewJsonMatrixTest extends LaravelTestCase
{
    public function response(): \Laratesto\Testing\LaravelResponse
    {
        $response = $this->get('/users');
        $response->assertContent('<html></html>');
        $response->assertSessionHas('status');
        $response->assertSessionHas('user.id', 1);
        $response->assertSessionHas(['status' => 'ok', 'partner']);
        $response->assertSessionMissing('expired');
        $response->assertSessionMissing(['expired', 'revoked']);
        $response->assertSessionHasErrors();
        $response->assertSessionHasErrors('email');
        $response->assertSessionHasErrors(['email', 'name']);
        $response->assertSessionHasErrors(['email' => 'required'], ':message', 'login');
        $response->assertJsonMissingPath('user.email');
        $response->assertJsonStructure(['id', 'user' => ['name']]);
        $response->assertJsonValidationErrors('email');
        $response->assertJsonValidationErrors(['email', 'name'], 'errors');
        $response->assertViewHas('user');
        $response->assertViewHas('user.name', 'Taylor');
        $response->assertViewHas(['user', 'post']);
        $response->assertContent('<html></html>')->assertSessionHas('status');
        $fluent = $response->assertViewHas('user');
        $fluent->assertJsonStructure(['id']);

        return $response;
    }
}
