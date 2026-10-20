<?php

declare(strict_types=1);

namespace Laratesto\Testing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Laratesto\Runtime\HttpTestContext;

/**
 * HTTP testing client shared by the facade and legacy helpers for one test execution.
 *
 * @api
 */
final class HttpClient
{
    use Concerns\HttpRequests {
        get as public;
        getJson as public;
        post as public;
        put as public;
        patch as public;
        delete as public;
        postJson as public;
        deleteJson as public;
        call as public;
    }

    /** @var array<non-empty-string, string> Cookies collected from previous responses, keyed by name. */
    private array $cookies = [];

    /** @var array<non-empty-string, string> Default headers applied to every request. */
    private array $defaultHeaders = [];

    /** @var array<non-empty-string, string> Server variables applied to every request. */
    private array $serverVariables = [];

    private bool $followRedirects = false;

    /** @internal Created by LaravelPlugin after application bootstrap. */
    public function __construct(private readonly HttpTestContext $context) {}

    /**
     * Set a single default header for subsequent requests.
     */
    public function withHeader(string $name, string $value): static
    {
        $this->app();
        $this->defaultHeaders[$name] = $value;

        return $this;
    }

    /**
     * Merge headers into the defaults applied to every request.
     *
     * @param array<non-empty-string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        $this->app();
        $this->defaultHeaders = \array_merge($this->defaultHeaders, $headers);

        return $this;
    }

    /**
     * Remove a default header from subsequent requests.
     */
    public function withoutHeader(string $name): static
    {
        $this->app();
        foreach (\array_keys($this->defaultHeaders) as $header) {
            if (\strcasecmp($header, $name) === 0) {
                unset($this->defaultHeaders[$header]);
            }
        }

        return $this;
    }

    /**
     * Set the bearer token for subsequent requests.
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->app();
        $this->defaultHeaders['Authorization'] = $type . ' ' . $token;

        return $this;
    }

    /**
     * Seed session values before the next request.
     *
     * @param array<string, mixed> $data
     */
    public function withSession(array $data): static
    {
        $this->app();
        $session = $this->session();

        foreach ($data as $key => $value) {
            $session->put($key, $value);
        }

        // The bridge deliberately carries cookies between requests. Persist the
        // in-memory override and bind its ID to the next request so StartSession
        // cannot reload older values from the previously captured cookie.
        $session->save();
        $this->cookies[$session->getName()] = $this->encryptedCookie(
            $session->getName(),
            $session->getId(),
        );

        return $this;
    }

    /**
     * Add a Laravel-encrypted cookie to subsequent requests.
     */
    public function withCookie(string $name, string $value): static
    {
        $this->app();
        $this->cookies[$name] = $this->encryptedCookie($name, $value);

        return $this;
    }

    /**
     * Add Laravel-encrypted cookies to subsequent requests.
     *
     * @param array<non-empty-string, string> $cookies
     */
    public function withCookies(array $cookies): static
    {
        $this->app();
        foreach ($cookies as $name => $value) {
            $this->withCookie($name, $value);
        }

        return $this;
    }

    /**
     * Follow redirect responses until a non-redirect response is reached.
     */
    public function followingRedirects(): static
    {
        $this->app();
        $this->followRedirects = true;

        return $this;
    }

    /**
     * Merge server variables (e.g. `REMOTE_ADDR`) applied to every request.
     *
     * @param array<non-empty-string, string> $server
     */
    public function withServerVariables(array $server): static
    {
        $this->app();
        $this->serverVariables = \array_merge($this->serverVariables, $server);

        return $this;
    }

    /**
     * Set the referer URL for the next request (for redirect-back tests).
     */
    public function from(string $url): static
    {
        $this->app();
        $this->defaultHeaders['Referer'] = $url;

        return $this;
    }

    /**
     * Send a request with an arbitrary method.
     *
     * @param array<array-key, mixed> $parameters
     */
    public function sendRequest(
        string $method,
        string $uri,
        array $parameters = [],
        array $headers = [],
        ?string $content = null,
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?callable $redirect = null,
    ): LaravelResponse {
        $kernel = $this->app()->make(HttpKernel::class);

        $headers = \array_merge($this->defaultHeaders, $headers);

        $server = \array_replace($this->serverVariables, self::prepareServer($headers), $server);

        $request = Request::create(
            uri: $uri,
            method: $method,
            parameters: $parameters,
            cookies: \array_merge($this->cookies, $cookies),
            files: $files,
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

        $wrapped = new LaravelResponse($response);

        if (!$this->followRedirects) {
            return $wrapped;
        }

        $this->followRedirects = false;

        while ($wrapped->response()->isRedirect()) {
            $location = $wrapped->header('Location');
            if ($location === null) {
                break;
            }

            $wrapped = $redirect === null ? $this->get($location) : $redirect($location);
        }

        return $wrapped;
    }

    private function app(): Application
    {
        return $this->context->application();
    }

    private function session(): \Illuminate\Contracts\Session\Session
    {
        $session = $this->app()->make('session.store');
        if (!$session->isStarted()) {
            $session->start();
        }
        return $session;
    }

    /**
     * Collect Set-Cookie header values from the response so subsequent
     * requests in the same test preserve the session.
     *
     * Cookies the response expires (a past expiry time with an empty value)
     * are removed from the jar instead of being replayed.
     */
    private function captureCookies(\Symfony\Component\HttpFoundation\Response $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);

                continue;
            }

            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
    }

    private function encryptedCookie(string $name, string $value): string
    {
        $key = $this->app()->make('encrypter')->getKey();

        return \encrypt(CookieValuePrefix::create($name, $key) . $value, false);
    }

    /**
     * Convert header names into the server variables expected by Symfony requests.
     *
     * `Content-Type` and `Content-Length` map to `CONTENT_TYPE`/`CONTENT_LENGTH`
     * (not `HTTP_*`) so they are recognised as actual request headers.
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

            if ($upper === 'CONTENT_TYPE' || $upper === 'CONTENT_LENGTH') {
                $server[$upper] = $value;
                continue;
            }

            $server[\str_starts_with($upper, 'HTTP_') ? $upper : 'HTTP_' . $upper] = $value;
        }

        return $server;
    }
}
