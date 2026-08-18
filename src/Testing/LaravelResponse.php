<?php

declare(strict_types=1);

namespace Testo\Bridge\Laravel\Testing;

use Symfony\Component\HttpFoundation\Response;
use Testo\Assert;

/**
 * Test-oriented wrapper over an HTTP response returned by the kernel.
 *
 * The `assert*` methods use Testo assertions, so no PHPUnit is involved.
 *
 * @api
 */
final readonly class LaravelResponse
{
    public function __construct(
        private Response $response,
    ) {}

    /**
     * The underlying Symfony response.
     */
    public function response(): Response
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->response->headers->all();
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->response->headers->get($name, $default);
    }

    public function body(): string
    {
        return (string) $this->response->getContent();
    }

    /**
     * Decode a JSON response body into an array.
     *
     * @throws \RuntimeException If the body is not valid JSON.
     */
    public function json(): mixed
    {
        try {
            return \json_decode($this->body(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf(
                'The response is not valid JSON: %s. Body: "%s".',
                $e->getMessage(),
                \mb_substr($this->body(), 0, 255),
            ), previous: $e);
        }
    }

    public function assertOk(): static
    {
        return $this->assertStatus(200);
    }

    public function assertStatus(int $status): static
    {
        Assert::same($this->status(), $status, \sprintf(
            'Expected response status %d, got %d. Body: "%s".',
            $status,
            $this->status(),
            \mb_substr($this->body(), 0, 255),
        ));

        return $this;
    }

    public function assertHeader(string $name, ?string $value = null): static
    {
        $actual = $this->header($name);

        Assert::false(
            $actual === null,
            \sprintf('The response does not have the header "%s".', $name),
        );

        if ($value !== null) {
            Assert::same($actual, $value, \sprintf(
                'Expected header "%s" to be "%s", got "%s".',
                $name,
                $value,
                (string) $actual,
            ));
        }

        return $this;
    }

    /**
     * Assert that the response body is JSON equal to the expected value.
     *
     * @param array<array-key, mixed> $expected
     */
    public function assertJson(array $expected): static
    {
        Assert::same($this->json(), $expected, 'The JSON response does not match the expected value.');

        return $this;
    }

    public function assertSee(string $needle): static
    {
        Assert::true(
            \str_contains($this->body(), $needle),
            \sprintf('The response body does not contain "%s".', $needle),
        );

        return $this;
    }
}
