<?php

declare(strict_types=1);

namespace Laratesto\Testing;

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
     * Optionally extract a nested value by dot-path, matching Laravel's
     * `TestResponse::json($key)`.
     *
     * @throws \RuntimeException If the body is not valid JSON.
     */
    public function json(?string $key = null): mixed
    {
        try {
            $decoded = \json_decode($this->body(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf(
                'The response is not valid JSON: %s. Body: "%s".',
                $e->getMessage(),
                \mb_substr($this->body(), 0, 255),
            ), previous: $e);
        }

        return $key !== null ? \data_get($decoded, $key) : $decoded;
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

    public function assertSee(string $needle, bool $escape = true): static
    {
        $value = $escape ? \htmlspecialchars($needle, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8', false) : $needle;

        Assert::true(
            \str_contains($this->body(), $value),
            \sprintf('The response body does not contain "%s".', $needle),
        );

        return $this;
    }

    /**
     * Assert that the response body does not contain the given text.
     *
     * @param non-empty-string|list<non-empty-string> $text
     */
    public function assertDontSee(string|array $text, bool $escape = true): static
    {
        foreach (\is_array($text) ? $text : [$text] as $needle) {
            $value = $escape ? \htmlspecialchars($needle, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8', false) : $needle;

            Assert::false(
                \str_contains($this->body(), $value),
                \sprintf('The response body contains "%s".', $needle),
            );
        }

        return $this;
    }

    /**
     * Assert that the response is a redirect, optionally to a specific URI.
     */
    public function assertRedirect(?string $uri = null): static
    {
        Assert::true(
            $this->response->isRedirect(),
            \sprintf(
                'Expected response to be a redirect (201, 301, 302, 303, 307, 308), got status %d. Body: "%s".',
                $this->status(),
                \mb_substr($this->body(), 0, 255),
            ),
        );

        if ($uri !== null) {
            Assert::same(
                $this->header('Location'),
                $uri,
                \sprintf('Expected redirect to "%s", got "%s".', $uri, (string) $this->header('Location')),
            );
        }

        return $this;
    }

    /**
     * Assert that the response does not have the given header.
     */
    public function assertHeaderMissing(string $name): static
    {
        Assert::false(
            $this->response->headers->has($name),
            \sprintf('Unexpected header "%s" is present on the response.', $name),
        );

        return $this;
    }

    /**
     * Assert that the JSON at the given dot-path matches the expected value.
     *
     * Supports closures as the expectation, matching Laravel's `assertJsonPath`.
     *
     * @param non-empty-string $path
     */
    public function assertJsonPath(string $path, mixed $expected): static
    {
        $actual = \data_get($this->json(), $path);

        if ($expected instanceof \Closure) {
            Assert::true(
                $expected($actual),
                \sprintf(
                    'The JSON at path "%s" did not satisfy the given closure. Actual: %s.',
                    $path,
                    self::describe($actual),
                ),
            );

            return $this;
        }

        Assert::same(
            $actual,
            $expected,
            \sprintf(
                'The JSON at path "%s" does not match. Expected: %s, actual: %s.',
                $path,
                self::describe($expected),
                self::describe($actual),
            ),
        );

        return $this;
    }

    /**
     * Assert that the JSON at the given dot-path does not exist.
     *
     * @param non-empty-string $path
     */
    public function assertJsonMissingPath(string $path): static
    {
        $value = \data_get($this->json(), $path);
        $exists = \count(\array_filter(\explode('.', $path))) > 0 && $value !== null && $value !== '__laravel_missing__';

        Assert::false(
            $exists,
            \sprintf('The JSON at path "%s" exists: %s.', $path, self::describe($value)),
        );

        return $this;
    }

    /**
     * Assert that the response JSON has the expected key structure.
     *
     * Supports the `'*'` wildcard to assert the structure of every element in a list:
     *
     * ```php
     * $response->assertJsonStructure([
     *     'id',
     *     'user' => ['name', 'email'],
     *     'items' => ['*' => ['id', 'name']],
     * ]);
     * ```
     *
     * @param array<array-key, mixed> $structure
     */
    public function assertJsonStructure(array $structure): static
    {
        $this->assertStructureAt($structure, $this->json(), '<root>');

        return $this;
    }

    // ---- Status shortcuts ----

    public function assertCreated(): static
    {
        return $this->assertStatus(201);
    }

    public function assertBadRequest(): static
    {
        return $this->assertStatus(400);
    }

    public function assertUnauthorized(): static
    {
        return $this->assertStatus(401);
    }

    public function assertForbidden(): static
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): static
    {
        return $this->assertStatus(404);
    }

    public function assertUnprocessable(): static
    {
        return $this->assertStatus(422);
    }

    public function assertTooManyRequests(): static
    {
        return $this->assertStatus(429);
    }

    public function assertServiceUnavailable(): static
    {
        return $this->assertStatus(503);
    }

    /**
     * Assert that the response body exactly matches the given content.
     */
    public function assertContent(string $content): static
    {
        Assert::same($this->body(), $content, 'The response body does not match the expected content.');

        return $this;
    }

    /**
     * Recursive key-structure check, ported from
     * `Illuminate\Testing\AssertableJsonString::assertStructure()`.
     *
     * @param array<array-key, mixed> $structure
     */
    private function assertStructureAt(array $structure, mixed $data, string $path): void
    {
        Assert::true(
            \is_array($data),
            \sprintf('The JSON at "%s" is not an array.', $path),
        );

        foreach ($structure as $key => $value) {
            if (\is_array($value) && $key === '*') {
                foreach ($data as $item) {
                    $this->assertStructureAt($structure['*'], $item, $path . '.*');
                }

                continue;
            }

            if (\is_array($value)) {
                $childPath = $path === '<root>' ? (string) $key : $path . '.' . $key;

                Assert::true(
                    \array_key_exists($key, $data),
                    \sprintf('The JSON at "%s" is missing the key "%s".', $path, (string) $key),
                );

                $this->assertStructureAt($structure[$key], $data[$key], $childPath);

                continue;
            }

            Assert::true(
                \array_key_exists($value, $data),
                \sprintf('The JSON at "%s" is missing the key "%s".', $path, (string) $value),
            );
        }
    }

    private static function describe(mixed $value): string
    {
        return \is_scalar($value) || $value === null
            ? \var_export($value, true)
            : (string) \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
