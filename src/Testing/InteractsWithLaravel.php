<?php

declare(strict_types=1);

namespace Laratesto\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Testo\Assert;

/**
 * Interaction helpers for tests that run inside a booted Laravel application.
 *
 * Use the trait directly in a plain test class, or extend {@see LaravelTestCase}.
 * The bridge injects the application before every test; no Laravel or PHPUnit
 * base class is involved.
 *
 * @api
 */
trait InteractsWithLaravel
{
    private ?Application $laravelApplication = null;

    /** @var array<non-empty-string, string> Cookies collected from previous responses, keyed by name. */
    private array $cookies = [];

    /** @var array<non-empty-string, string> Default headers applied to every request. */
    private array $defaultHeaders = [];

    /** @var array<non-empty-string, string> Server variables applied to every request. */
    private array $serverVariables = [];

    /**
     * @internal Called by the bridge, not by user code.
     */
    public function setLaravelApplication(Application $application): void
    {
        $this->laravelApplication = $application;
        $this->cookies = [];
        $this->defaultHeaders = [];
        $this->serverVariables = [];

        $this->setUpLaravel();
    }

    /**
     * Hook for subclasses: runs after the Laravel application is booted and
     * injected, before the test method. Mirrors the setup phase of Laravel's
     * PHPUnit TestCase — per-test state should be initialized here (the test
     * case instance is reused across test methods by Testo).
     */
    protected function setUpLaravel(): void
    {
        // No-op by default.
    }

    /**
     * The Laravel application booted for the current test.
     */
    protected function app(): Application
    {
        return $this->laravelApplication ?? throw new \RuntimeException(
            'The Laravel application is not available. '
            . 'Use the LaravelTestCase base class or the InteractsWithLaravel trait '
            . 'in a suite with the LaravelPlugin registered.',
        );
    }

    /**
     * Resolve a service from the container.
     */
    protected function make(string $abstract): mixed
    {
        return $this->app()->make($abstract);
    }

    // ---- HTTP request helpers (with automatic cookie bridge) ----

    /**
     * Set a single default header for subsequent requests.
     */
    protected function withHeader(string $name, string $value): static
    {
        $this->defaultHeaders[$name] = $value;

        return $this;
    }

    /**
     * Merge headers into the defaults applied to every request.
     *
     * @param array<non-empty-string, string> $headers
     */
    protected function withHeaders(array $headers): static
    {
        $this->defaultHeaders = \array_merge($this->defaultHeaders, $headers);

        return $this;
    }

    /**
     * Set the bearer token for subsequent requests.
     */
    protected function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->defaultHeaders['Authorization'] = $type . ' ' . $token;

        return $this;
    }

    /**
     * Merge server variables (e.g. `REMOTE_ADDR`) applied to every request.
     *
     * @param array<non-empty-string, string> $server
     */
    protected function withServerVariables(array $server): static
    {
        $this->serverVariables = \array_merge($this->serverVariables, $server);

        return $this;
    }

    /**
     * Set the referer URL for the next request (for redirect-back tests).
     */
    protected function from(string $url): static
    {
        $this->defaultHeaders['Referer'] = $url;

        return $this;
    }

    /**
     * Send a GET request through the HTTP kernel.
     */
    protected function get(string $uri, array $headers = []): LaravelResponse
    {
        return $this->sendRequest(Request::METHOD_GET, $uri, headers: $headers);
    }

    /**
     * Send a GET request expecting a JSON response.
     */
    protected function getJson(string $uri, array $headers = []): LaravelResponse
    {
        return $this->sendRequest(
            method: Request::METHOD_GET,
            uri: $uri,
            headers: $headers + [
                'ACCEPT' => 'application/json',
            ],
        );
    }

    /**
     * Send a POST request with form parameters.
     *
     * @param array<array-key, mixed> $parameters
     */
    protected function post(string $uri, array $parameters = [], array $headers = []): LaravelResponse
    {
        return $this->sendRequest(Request::METHOD_POST, $uri, $parameters, $headers);
    }

    /**
     * Send a PUT request with form parameters.
     *
     * @param array<array-key, mixed> $parameters
     */
    protected function put(string $uri, array $parameters = [], array $headers = []): LaravelResponse
    {
        return $this->sendRequest(Request::METHOD_PUT, $uri, $parameters, $headers);
    }

    /**
     * Send a PATCH request with form parameters.
     *
     * @param array<array-key, mixed> $parameters
     */
    protected function patch(string $uri, array $parameters = [], array $headers = []): LaravelResponse
    {
        return $this->sendRequest(Request::METHOD_PATCH, $uri, $parameters, $headers);
    }

    /**
     * Send a DELETE request.
     *
     * @param array<array-key, mixed> $parameters
     */
    protected function delete(string $uri, array $parameters = [], array $headers = []): LaravelResponse
    {
        return $this->sendRequest(Request::METHOD_DELETE, $uri, $parameters, $headers);
    }

    /**
     * Send a POST request with a JSON body.
     *
     * @param array<array-key, mixed> $payload
     */
    protected function postJson(string $uri, array $payload = [], array $headers = []): LaravelResponse
    {
        return $this->sendRequest(
            method: Request::METHOD_POST,
            uri: $uri,
            headers: $headers + [
                'CONTENT_TYPE' => 'application/json',
                'ACCEPT' => 'application/json',
            ],
            content: \json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Send a request with an arbitrary method.
     *
     * @param array<array-key, mixed> $parameters
     */
    protected function sendRequest(
        string $method,
        string $uri,
        array $parameters = [],
        array $headers = [],
        ?string $content = null,
    ): LaravelResponse {
        $kernel = $this->app()->make(HttpKernel::class);

        $headers = \array_merge($this->defaultHeaders, $headers);

        $server = self::prepareServer($headers) + $this->serverVariables;

        $request = Request::create(
            uri: $uri,
            method: $method,
            parameters: $parameters,
            cookies: $this->cookies,
            files: [],
            server: $server,
            content: $content,
        );

        // For relative URIs, force the Host header from the configured app URL
        // so URL generation stays consistent with redirects (Laravel's own
        // test requests carry the app URL host).
        if (!\str_starts_with($uri, 'http://') && !\str_starts_with($uri, 'https://')) {
            $appUrl = (string) $this->app()['config']->get('app.url', 'http://localhost');
            $host = (string) \parse_url($appUrl, \PHP_URL_HOST);
            $port = \parse_url($appUrl, \PHP_URL_PORT);

            if ($host !== '') {
                $request->headers->set('HOST', $port !== null && $port !== false ? $host . ':' . $port : $host);
            }
        }

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $this->captureCookies($response);

        return new LaravelResponse($response);
    }

    /**
     * Run an Artisan command and return a testable result.
     *
     * @param array<array-key, string|bool|int> $parameters
     */
    protected function artisan(string $command, array $parameters = []): PendingArtisanCommand
    {
        return new PendingArtisanCommand(
            kernel: $this->app()->make(ConsoleKernel::class),
            command: $command,
            parameters: $parameters,
        );
    }

    /**
     * Disable middleware for subsequent HTTP requests.
     *
     * With no arguments, all middleware is disabled. With class names, those
     * middleware are replaced by a pass-through handler, matching Laravel's
     * `withoutMiddleware`.
     *
     * @param non-empty-string|list<non-empty-string>|null $middleware
     */
    protected function withoutMiddleware(string|array|null $middleware = null): static
    {
        if ($middleware === null) {
            $this->app()->instance('middleware.disable', true);

            return $this;
        }

        foreach ((array) $middleware as $abstract) {
            $this->app()->instance($abstract, new class
            {
                public function handle($request, $next)
                {
                    return $next($request);
                }
            });
        }

        return $this;
    }

    // ---- Authentication helpers ----

    /**
     * Set the currently logged-in user without a session.
     *
     * The user is set on the guard directly, so it persists across all
     * subsequent HTTP requests in the same test, regardless of session cookies.
     */
    protected function actingAs(Authenticatable $user, ?string $guard = null): static
    {
        $this->guard($guard)->setUser($user);
        $this->app()['auth']->shouldUse($guard ?? 'web');

        return $this;
    }

    /**
     * Clear the authenticated user on the given guard.
     */
    protected function actingAsGuest(?string $guard = null): static
    {
        $this->guard($guard)->forgetUser();

        return $this;
    }

    /**
     * Assert that the user is authenticated.
     */
    protected function assertAuthenticated(?string $guard = null): static
    {
        Assert::true($this->guard($guard)->check(), 'The user is not authenticated.');

        return $this;
    }

    /**
     * Assert that the user is not authenticated.
     */
    protected function assertGuest(?string $guard = null): static
    {
        Assert::false($this->guard($guard)->check(), 'The user is authenticated.');

        return $this;
    }

    /**
     * Assert that the user is authenticated as the given user.
     */
    protected function assertAuthenticatedAs(Authenticatable $user, ?string $guard = null): static
    {
        $expected = $this->guard($guard)->user();

        Assert::notNull($expected, 'The current user is not authenticated.');
        Assert::same($expected->getAuthIdentifier(), $user->getAuthIdentifier(), 'The currently authenticated user is not who was expected.');

        return $this;
    }

    // ---- Database assertions ----

    /**
     * Assert that a table contains a row matching the given data.
     *
     * @param array<string, mixed> $data
     */
    protected function assertDatabaseHas(string $table, array $data, ?string $connection = null): static
    {
        Assert::true($this->table($table, $connection)->where($data)->exists(), \sprintf(
            'Failed asserting that the table [%s] contains a row matching %s.',
            $table,
            \json_encode($data, \JSON_THROW_ON_ERROR),
        ));

        return $this;
    }

    /**
     * Assert that a table does not contain a row matching the given data.
     *
     * @param array<string, mixed> $data
     */
    protected function assertDatabaseMissing(string $table, array $data, ?string $connection = null): static
    {
        Assert::false($this->table($table, $connection)->where($data)->exists(), \sprintf(
            'Failed asserting that the table [%s] does not contain a row matching %s.',
            $table,
            \json_encode($data, \JSON_THROW_ON_ERROR),
        ));

        return $this;
    }

    /**
     * Assert the count of rows in a table.
     */
    protected function assertDatabaseCount(string $table, int $count, ?string $connection = null): static
    {
        $actual = $this->table($table, $connection)->count();
        Assert::same($actual, $count, \sprintf(
            'Failed asserting that the table [%s] has %d rows. Found %d.',
            $table,
            $count,
            $actual,
        ));

        return $this;
    }

    // ---- Session assertions ----

    /**
     * Assert that the session has a given value.
     */
    protected function assertSessionHas(string $key, mixed $value = null): static
    {
        $session = $this->session();

        Assert::true($session->has($key), \sprintf('Session is missing expected key [%s].', $key));

        if ($value !== null) {
            Assert::same($session->get($key), $value, \sprintf(
                'Session key [%s] has value [%s] instead of expected [%s].',
                $key,
                \is_scalar($session->get($key)) ? (string) $session->get($key) : \json_encode($session->get($key)),
                \is_scalar($value) ? (string) $value : \json_encode($value),
            ));
        }

        return $this;
    }

    /**
     * Assert that the session is missing a given key.
     */
    protected function assertSessionMissing(string $key): static
    {
        Assert::false($this->session()->has($key), \sprintf('Session has unexpected key [%s].', $key));

        return $this;
    }

    /**
     * Assert that the session has validation errors for the given keys.
     *
     * @param array<non-empty-string>|list<non-empty-string> $keys Field names.
     *        String keys are treated as field => format; integer keys as field => any.
     */
    protected function assertSessionHasErrors(array $keys = []): static
    {
        $errors = $this->session()->get('errors');

        Assert::notNull($errors, 'Session is missing expected errors bag.');

        $bag = $errors instanceof \Illuminate\Support\ViewErrorBag
            ? $errors->getBag('default')
            : $errors;

        foreach ($keys as $key => $field) {
            if (\is_int($key)) {
                Assert::true($bag->has($field), \sprintf('Session is missing error for field [%s].', $field));
            } else {
                Assert::true($bag->has($key), \sprintf('Session is missing error for field [%s].', $key));
            }
        }

        return $this;
    }

    // ---- Artisan assertion ----

    /**
     * Assert that an Artisan command exits with the given code.
     *
     * @param array<array-key, string|bool|int> $parameters
     */
    protected function assertExitCode(int $code, string $command, array $parameters = []): static
    {
        $this->artisan($command, $parameters)->assertExitCode($code);

        return $this;
    }

    // ---- Internal helpers ----

    /**
     * Get the current session store from the booted application.
     */
    protected function session(): \Illuminate\Contracts\Session\Session
    {
        $session = $this->app()->make('session.store');

        if (!$session->isStarted()) {
            $session->start();
        }

        return $session;
    }

    private function guard(?string $name = null): Guard
    {
        return $this->app()->make('auth')->guard($name);
    }

    private function table(string $table, ?string $connection = null): \Illuminate\Database\Query\Builder
    {
        return $this->app()->make('db')->connection($connection)->table($table);
    }

    /**
     * Collect Set-Cookie header values from the response so subsequent
     * requests in the same test preserve the session.
     */
    private function captureCookies(\Symfony\Component\HttpFoundation\Response $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
    }

    /**
     * Convert header names into the server variables expected by Symfony requests.
     *
     * @param array<non-empty-string, string> $headers
     *
     * @return array<non-empty-string, string>
     */
    private static function prepareServer(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $upper = \strtoupper(\str_replace('-', '_', $name));

            if (\str_starts_with($upper, 'HTTP_') || $upper === 'CONTENT_TYPE' || $upper === 'CONTENT_LENGTH') {
                $server[$upper] = $value;
                continue;
            }

            $server['HTTP_' . $upper] = $value;
        }

        return $server;
    }
}