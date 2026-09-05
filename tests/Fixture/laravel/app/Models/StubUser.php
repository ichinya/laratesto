<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Minimal authenticatable stub for auth-helper semantics tests.
 *
 * Unlike {@see TestUser} it carries a mutable `wasRecentlyCreated` flag and
 * accepts string identifiers, so tests can exercise the strict identifier
 * comparison of `assertAuthenticatedAs`.
 */
final class StubUser implements Authenticatable
{
    public bool $wasRecentlyCreated = false;

    public function __construct(
        public int|string $id,
        public string $name = 'Stub',
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return null;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(#[\SensitiveParameter] $value): void
    {
        // Not used by the stub.
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
