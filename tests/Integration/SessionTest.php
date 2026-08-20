<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Laratesto\Testing\LaravelTestCase;
use Testo\Assert;

final class SessionTest extends LaravelTestCase
{
    public function testSessionStateIsCapturedAfterARequest(): void
    {
        $this->get('/session/write')->assertOk();

        $this->assertSessionHas('key', 'value-from-session');
    }

    public function testSessionPersistsAcrossRequestsViaCookies(): void
    {
        $this->get('/session/write')->assertOk();

        $this->get('/session/read')
            ->assertOk()
            ->assertJson(['key' => 'value-from-session']);
    }

    public function testValidationErrorsAreCapturedInSession(): void
    {
        $response = $this->post('/session/flash', []);

        Assert::same($response->status(), 302);
        $this->assertSessionHasErrors(['email']);
    }

    public function testSessionMissingFailsOnPresentKey(): void
    {
        $this->get('/session/write')->assertOk();

        $failed = false;

        try {
            $this->assertSessionMissing('key');
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertSessionMissing must fail when the key is present.');
    }
}
