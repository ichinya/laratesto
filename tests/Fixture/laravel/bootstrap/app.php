<?php

declare(strict_types=1);

use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Cookie\CookieServiceProvider;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
    )
    ->withProviders([
        EncryptionServiceProvider::class,
        CookieServiceProvider::class,
        SessionServiceProvider::class,
        ValidationServiceProvider::class,
        AuthServiceProvider::class,
        \App\Providers\TestAuthServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
