<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use App\Models\StubUser;
use App\Models\TestUser;
use Laratesto\Testing\LaravelTestCase;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Assert\State\Assertion\AssertionException;

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

    public function testActingAsKeepsTheConfiguredNonWebDefaultGuard(): void
    {
        $this->useApiGuard();

        $this->actingAs(new TestUser(42, 'Testy'));

        Assert::same($this->app()['auth']->getDefaultDriver(), 'api');
        $this->assertAuthenticated();
        $this->assertGuest('web');

        $this->get('/auth/me')
            ->assertOk()
            ->assertJson(['id' => 42]);
    }

    public function testActingAsWithExplicitGuardBecomesTheDefault(): void
    {
        $this->defineApiGuard();

        $this->actingAs(new TestUser(42, 'Testy'), 'api');

        Assert::same($this->app()['auth']->getDefaultDriver(), 'api');
        $this->assertAuthenticated('api');
        $this->assertGuest('web');

        $this->get('/auth/me')
            ->assertOk()
            ->assertJson(['id' => 42]);
    }

    public function testActingAsGuestSelectsTheGivenGuard(): void
    {
        $this->defineApiGuard();

        $this->actingAsGuest('api');

        Assert::same($this->app()['auth']->getDefaultDriver(), 'api');
        $this->assertGuest('api');
        $this->assertGuest('web');

        $this->getJson('/auth/me')->assertUnauthorized();
    }

    public function testActingAsGuestKeepsTheConfiguredDefaultGuard(): void
    {
        $this->useApiGuard();

        $this->actingAsGuest();

        Assert::same($this->app()['auth']->getDefaultDriver(), 'api');
        $this->assertGuest('api');
    }

    public function testActingAsClearsTheRecentlyCreatedFlag(): void
    {
        $user = new StubUser(42);
        $user->wasRecentlyCreated = true;

        $this->actingAs($user);

        Assert::false($user->wasRecentlyCreated);
        $this->assertAuthenticatedAs($user);
    }

    #[ExpectException(AssertionException::class)]
    public function testAssertAuthenticatedAsRejectsADifferentUserClassWithTheSameId(): void
    {
        $this->actingAs(new TestUser(42, 'Testy'));

        $this->assertAuthenticatedAs(new StubUser(42));
    }

    #[ExpectException(AssertionException::class)]
    public function testAssertAuthenticatedAsComparesIdentifiersStrictly(): void
    {
        $this->actingAs(new StubUser(42));

        $this->assertAuthenticatedAs(new StubUser('42'));
    }

    /**
     * Register a `token`-driven `api` guard, keeping `web` the default.
     */
    private function defineApiGuard(): void
    {
        $this->app()['config']->set('auth.guards.api', ['driver' => 'token', 'provider' => 'users']);
    }

    /**
     * Register the `api` guard and make it the configured default.
     */
    private function useApiGuard(): void
    {
        $this->defineApiGuard();
        $this->app()['config']->set('auth.defaults.guard', 'api');
    }
}
