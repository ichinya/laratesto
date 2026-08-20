<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\StaticUserProvider;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the in-memory user provider under the `static-users` driver name.
 */
final class TestAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['auth']->provider(
            'static-users',
            static fn ($app, array $config): UserProvider => new StaticUserProvider(),
        );
    }
}
