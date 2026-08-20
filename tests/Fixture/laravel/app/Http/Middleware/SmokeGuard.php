<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards a route for the `withoutMiddleware` test: returns 403 unless
 * a specific header is present. Used to verify that the bridge's
 * `withoutMiddleware()` correctly disables it.
 */
final class SmokeGuard
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        if ($request->header('X-Smoke-Secret') !== 'open-sesame') {
            abort(403, 'SmokeGuard blocked the request.');
        }

        return $next($request);
    }
}