<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * In-memory test user for the fixture application.
 */
final class TestUser implements Authenticatable
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
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
        // Not used by the in-memory provider.
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
