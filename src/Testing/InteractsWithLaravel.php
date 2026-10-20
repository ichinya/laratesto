<?php

declare(strict_types=1);

namespace Laratesto\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\Wormhole;
use Illuminate\Support\Carbon;
use Testo\Assert;
use Testo\Common\Attribute\AssertMethod;

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
    use Concerns\HttpRequests;
    use \Illuminate\Foundation\Testing\Concerns\InteractsWithContainer;
    use \Illuminate\Foundation\Testing\Concerns\InteractsWithExceptionHandling;

    /** Application alias required by Laravel's container and exception helpers. */
    protected $app;

    private ?Application $laravelApplication = null;

    private ?PhpUnitOutput $phpUnitOutput = null;

    private ?Internal\PhpUnitConsoleTestCase $phpUnitConsole = null;

    /** @internal */
    public function beginPhpUnitOutputCapture(): void
    {
        $this->phpUnitOutput = new PhpUnitOutput();
        $this->phpUnitOutput->start();
    }

    /** @internal */
    public function finishPhpUnitOutputCapture(bool $verify): void
    {
        $this->phpUnitOutput?->finish($verify);
    }

    /** @internal */
    public function closePhpUnitOutputCapture(): void
    {
        $this->phpUnitOutput?->close();
        $this->phpUnitOutput = null;
    }

    protected function expectOutputString(string $expectedString): void
    {
        if ($this->phpUnitOutput === null) {
            throw new \LogicException('expectOutputString() requires LaravelPlugin output capture.');
        }
        $this->phpUnitOutput->expected = $expectedString;
    }

    /**
     * @internal Called by the bridge, not by user code.
     */
    public function setLaravelApplication(Application $application): void
    {
        $this->laravelApplication = $application;
        $this->phpUnitConsole = null;
        $this->app = $application;
        $this->originalExceptionHandler = null;
        $this->originalVite = null;
        $this->originalMix = null;
        $this->originalDeferredCallbacksCollection = null;
    }

    /**
     * @internal Called by the lifecycle interceptor after database attributes.
     */
    public function setUpLaravelApplication(): void
    {
        $traits = \class_uses_recursive(static::class);
        if (isset($traits[WithFaker::class]) || isset($traits[\Illuminate\Foundation\Testing\WithFaker::class])) {
            $this->setUpFaker();
        }
        $this->setUpLaravel();
    }

    /**
     * @internal Called by the bridge after every test, not by user code.
     */
    public function tearDownLaravelApplication(): void
    {
        try {
            $this->tearDownLaravel();
        } finally {
            $this->clearLaravelApplicationReferences();
        }
    }

    /** @internal Releases bridge-owned state without invoking a user teardown hook. */
    public function clearLaravelApplicationReferences(): void
    {
        $this->laravelApplication = null;
        $this->phpUnitConsole = null;
        $this->app = null;
        $this->originalExceptionHandler = null;
        $this->originalVite = null;
        $this->originalMix = null;
        $this->originalDeferredCallbacksCollection = null;
        Carbon::setTestNow();
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
     * Hook for subclasses: runs after the test method and before Laravel's
     * global state is cleaned. PHPUnit tearDown() methods migrate here.
     */
    protected function tearDownLaravel(): void
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
        $this->httpClient()->withHeader($name, $value);

        return $this;
    }

    /**
     * Merge headers into the defaults applied to every request.
     *
     * @param array<non-empty-string, string> $headers
     */
    protected function withHeaders(array $headers): static
    {
        $this->httpClient()->withHeaders($headers);

        return $this;
    }

    /**
     * Remove a default header from subsequent requests.
     */
    protected function withoutHeader(string $name): static
    {
        $this->httpClient()->withoutHeader($name);

        return $this;
    }

    /**
     * Set the bearer token for subsequent requests.
     */
    protected function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->httpClient()->withToken($token, $type);

        return $this;
    }

    /**
     * Seed session values before the next request.
     *
     * @param array<string, mixed> $data
     */
    protected function withSession(array $data): static
    {
        $this->httpClient()->withSession($data);

        return $this;
    }

    /**
     * Add a Laravel-encrypted cookie to subsequent requests.
     */
    protected function withCookie(string $name, string $value): static
    {
        $this->httpClient()->withCookie($name, $value);

        return $this;
    }

    /**
     * Add Laravel-encrypted cookies to subsequent requests.
     *
     * @param array<non-empty-string, string> $cookies
     */
    protected function withCookies(array $cookies): static
    {
        $this->httpClient()->withCookies($cookies);

        return $this;
    }

    /**
     * Follow redirect responses until a non-redirect response is reached.
     */
    protected function followingRedirects(): static
    {
        $this->httpClient()->followingRedirects();

        return $this;
    }

    /**
     * Merge server variables (e.g. `REMOTE_ADDR`) applied to every request.
     *
     * @param array<non-empty-string, string> $server
     */
    protected function withServerVariables(array $server): static
    {
        $this->httpClient()->withServerVariables($server);

        return $this;
    }

    /**
     * Set the referer URL for the next request (for redirect-back tests).
     */
    protected function from(string $url): static
    {
        $this->httpClient()->from($url);

        return $this;
    }

    /**
     * Begin travelling forward in Laravel's test clock.
     */
    protected function travel(int $value): Wormhole
    {
        return new Wormhole($value);
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
        array $cookies = [],
        array $files = [],
        array $server = [],
    ): LaravelResponse {
        return $this->httpClient()->sendRequest(
            method: $method,
            uri: $uri,
            parameters: $parameters,
            headers: $headers,
            content: $content,
            cookies: $cookies,
            files: $files,
            server: $server,
            redirect: $this->get(...),
        );
    }

    /**
     * Preserve a migrated PHPUnit command's lifetime and deferred assertions.
     *
     * @param array<array-key, string|bool|int> $parameters
     */
    protected function pendingArtisan(string $command, array $parameters = []): DeferredArtisanCommand
    {
        if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
            throw new \LogicException('Deferred Artisan compatibility requires the PHPUnit compatibility shim or phpunit/phpunit to be available.');
        }
        $this->phpUnitConsole ??= new Internal\PhpUnitConsoleTestCase('consoleCompatibility');
        return new DeferredArtisanCommand(new \Illuminate\Testing\PendingCommand($this->phpUnitConsole, $this->app(), $command, $parameters));
    }

    /**
     * Run an Artisan command and return a testable result.
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
                public function handle(\Illuminate\Http\Request $request, \Closure $next): \Symfony\Component\HttpFoundation\Response
                {
                    return $next($request);
                }
            });
        }

        return $this;
    }

    protected function withMiddleware(string|array|null $middleware = null): static
    {
        if ($middleware === null) {
            $this->app()->instance('middleware.disable', false);
        } else {
            foreach ((array) $middleware as $abstract) {
                $this->app()->forgetInstance($abstract);
            }
        }
        return $this;
    }

    #[AssertMethod]
    protected function assertModelExists(\Illuminate\Database\Eloquent\Model $model): static
    {
        Assert::true($model->newQueryWithoutScopes()->whereKey($model->getKey())->exists(), 'The model does not exist in the database.');
        return $this;
    }

    // ---- Authentication helpers ----

    /**
     * Set the currently logged-in user without a session.
     *
     * Mirrors Laravel's `actingAs`/`be` pair: the user is set on the guard
     * directly, so it persists across all subsequent HTTP requests in the same
     * test regardless of session cookies, the guard becomes the default for
     * subsequent requests, and a freshly created model's `wasRecentlyCreated`
     * flag is cleared.
     */
    protected function actingAs(Authenticatable $user, ?string $guard = null): static
    {
        if (isset($user->wasRecentlyCreated) && $user->wasRecentlyCreated) {
            $user->wasRecentlyCreated = false;
        }

        $this->guard($guard)->setUser($user);
        $this->app()['auth']->shouldUse($guard);

        return $this;
    }

    /**
     * Clear the authenticated user on the given guard and make that guard the
     * default, mirroring Laravel's `actingAsGuest`.
     */
    protected function actingAsGuest(?string $guard = null): static
    {
        $this->guard($guard)->forgetUser();

        $this->app()['auth']->shouldUse($guard);

        return $this;
    }

    /**
     * Assert that the user is authenticated.
     */
    #[AssertMethod]
    protected function assertAuthenticated(?string $guard = null): static
    {
        Assert::true($this->guard($guard)->check(), 'The user is not authenticated.');

        return $this;
    }

    /**
     * Assert that the user is not authenticated.
     */
    #[AssertMethod]
    protected function assertGuest(?string $guard = null): static
    {
        Assert::false($this->guard($guard)->check(), 'The user is authenticated.');

        return $this;
    }

    /**
     * Assert that the user is authenticated as the given user.
     *
     * Mirrors Laravel: the given user must be an instance of the
     * authenticated user's class (a subclass of it is accepted) and their
     * identifiers must be strictly equal.
     */
    #[AssertMethod]
    protected function assertAuthenticatedAs(Authenticatable $user, ?string $guard = null): static
    {
        $expected = $this->guard($guard)->user();

        Assert::notNull($expected, 'The current user is not authenticated.');
        Assert::instanceOf($user, $expected::class, 'The currently authenticated user is not who was expected.');
        Assert::same($expected->getAuthIdentifier(), $user->getAuthIdentifier(), 'The currently authenticated user is not who was expected.');

        return $this;
    }

    // ---- Database assertions ----

    /**
     * Assert that a table contains a row matching the given data.
     *
     * @param array<string, mixed> $data
     */
    #[AssertMethod]
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
    #[AssertMethod]
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
    #[AssertMethod]
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
    #[AssertMethod]
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
    #[AssertMethod]
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
    #[AssertMethod]
    protected function assertSessionHasErrors(array $keys = []): static
    {
        $errors = $this->session()->get('errors');

        Assert::notNull($errors, 'Session is missing expected errors bag.');

        $bag = $errors instanceof \Illuminate\Support\ViewErrorBag
            ? $errors->getBag('default')
            : $errors;

        $has = static function (string $field) use ($bag): bool {
            if (\is_object($bag) && \method_exists($bag, 'has')) {
                return $bag->has($field);
            }

            return \is_array($bag) && \array_key_exists($field, $bag);
        };

        foreach ($keys as $key => $field) {
            if (\is_int($key)) {
                Assert::true($has($field), \sprintf('Session is missing error for field [%s].', $field));
            } else {
                Assert::true($has($key), \sprintf('Session is missing error for field [%s].', $key));
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
    #[AssertMethod]
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

    private function httpClient(): HttpClient
    {
        return $this->app()->make(HttpClient::class);
    }
}
