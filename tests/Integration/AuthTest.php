<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use App\Models\TestUser;
use Laratesto\Testing\LaravelTestCase;

final class AuthTest extends LaravelTestCase
{
    public function testActingAsAuthenticatesRequests(): void
    {
        $this->actingAs(new TestUser(42, 'Testy'));

        $this->get('/auth/me')
            ->assertOk()
            ->assertJson(['id' => 42]);

        $this->assertAuthenticatedAs(new TestUser(42, 'Testy'));
    }

    public function testActingAsGuestClearsTheUser(): void
    {
        $this->actingAs(new TestUser(7, 'GuestFlow'));
        $this->assertAuthenticated();

        $this->actingAsGuest();
        $this->assertGuest();
    }

    public function testGuestIsTheDefaultState(): void
    {
        $this->assertGuest();
    }
}
