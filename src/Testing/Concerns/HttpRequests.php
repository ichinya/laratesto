<?php

declare(strict_types=1);

namespace Laratesto\Testing\Concerns;

use Illuminate\Http\Request;
use Laratesto\Testing\LaravelResponse;

/** @internal Request signatures shared without bypassing legacy sendRequest() overrides. */
trait HttpRequests
{
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
     * Send a DELETE request with a JSON body.
     *
     * @param array<array-key, mixed> $payload
     */
    protected function deleteJson(string $uri, array $payload = [], array $headers = []): LaravelResponse
    {
        return $this->sendRequest(
            method: Request::METHOD_DELETE,
            uri: $uri,
            headers: $headers + [
                'CONTENT_TYPE' => 'application/json',
                'ACCEPT' => 'application/json',
            ],
            content: \json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Send a request using Laravel's low-level testing signature.
     *
     * @param array<array-key, mixed> $parameters
     * @param array<non-empty-string, string> $cookies
     * @param array<array-key, mixed> $files
     * @param array<non-empty-string, string> $server
     */
    protected function call(
        string $method,
        string $uri,
        array $parameters = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $content = null,
    ): LaravelResponse {
        return $this->sendRequest(
            method: $method,
            uri: $uri,
            parameters: $parameters,
            content: $content,
            cookies: $cookies,
            files: $files,
            server: $server,
        );
    }

}
