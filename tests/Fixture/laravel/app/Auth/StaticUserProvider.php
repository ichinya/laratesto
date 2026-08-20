<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\TestUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

/**
 * Minimal in-memory user provider: `actingAs()` sets the user on the guard
 * directly, so the provider only needs to exist for the auth configuration.
 */
final class StaticUserProvider implements UserProvider
{
    /** @var array<int, TestUser> */
    private array $users = [];

    public function retrieveById($identifier): ?TestUser
    {
        return $this->users[$identifier] ?? null;
    }

    public function retrieveByToken($identifier, $token): ?TestUser
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        // Not used.
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?TestUser
    {
        return null;
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        return false;
    }

    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[\SensitiveParameter] array $credentials,
        bool $force = false,
    ): bool {
        return false;
    }

    /**
     * Register a user for {@see retrieveById()} lookups.
     */
    public function addUser(TestUser $user): void
    {
        $this->users[$user->id] = $user;
    }
}
