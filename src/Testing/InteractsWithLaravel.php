<?php

declare(strict_types=1);

namespace Laratesto\Testing;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

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

    /**
     * @internal Called by the bridge, not by user code.
     */
    public function setLaravelApplication(Application $application): void
    {
        $this->laravelApplication = $application;
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

    /**
     * Send a GET request through the HTTP kernel.
     */
    protected function get(string $uri, array $headers = []): LaravelResponse
    {
        return $this->sendRequest(Request::METHOD_GET, $uri, headers: $headers);
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

        $request = Request::create(
            uri: $uri,
            method: $method,
            parameters: $parameters,
            cookies: [],
            files: [],
            server: self::prepareServer($headers),
            content: $content,
        );

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return new LaravelResponse($response);
    }

    /**
     * Run an Artisan command and return its exit code.
     *
     * @param array<array-key, string|bool|int> $parameters
     */
    protected function artisan(string $command, array $parameters = []): int
    {
        return $this->app()->make(ConsoleKernel::class)->call($command, $parameters);
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
