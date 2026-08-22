<?php

declare(strict_types=1);

namespace Laratesto\Testing;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
    /**
     * Symfony-compatible header access for tests migrated from Laravel's TestResponse.
     */
    public ResponseHeaderBag $headers;

    /**
     * The underlying response, matching Laravel TestResponse's public API.
     */
    public Response $baseResponse;

    public function __construct(
        private Response $response,
    ) {
        $this->baseResponse = $response;
        $this->headers = $response->headers;
    }

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
     * Symfony/Laravel compatibility alias.
     */
    public function getStatusCode(): int
    {
        return $this->status();
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
     * Symfony/Laravel compatibility alias.
     */
    public function getContent(): string
    {
        return $this->body();
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
            Assert::same(\strtolower((string) $actual), \strtolower($value), \sprintf(
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

    /**
     * Assert that the decoded response body exactly matches the expected JSON.
     *
     * @param array<array-key, mixed> $expected
     */
    public function assertExactJson(array $expected): static
    {
        Assert::same($this->json(), $expected, 'The JSON response does not exactly match the expected value.');

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
            $expected = self::absoluteLocation($uri);
            $actual = self::absoluteLocation((string) $this->header('Location'));

            Assert::same(
                $actual,
                $expected,
                \sprintf('Expected redirect to "%s", got "%s".', $expected, $actual),
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
     * Assert that the response has validation errors for the given field names.
     *
     * Mirrors `TestResponse::assertSessionHasErrors` (reads the session store).
     *
     * @param non-empty-string|list<non-empty-string> $keys
     */
    public function assertSessionHasErrors(string|array $keys = []): static
    {
        $errors = $this->session()->get('errors');

        Assert::notNull($errors, 'Session is missing expected errors bag.');

        // The errors bag may be a ViewErrorBag (validation redirect) or a flat
        // array (inline flash). Normalise to a common interface.
        $bag = $errors instanceof \Illuminate\Support\ViewErrorBag
            ? $errors->getBag('default')
            : $errors;

        if (\is_array($bag) && \array_key_exists('default', $bag)) {
            $bag = $bag['default'];
        }

        if ($bag instanceof \Illuminate\Contracts\Support\MessageBag) {
            $messagesByField = $bag->getMessages();
        } elseif (\is_array($bag) && \is_array($bag['messages'] ?? null)) {
            $messagesByField = $bag['messages'];
        } elseif (\is_array($bag)) {
            $messagesByField = $bag;
        } else {
            $messagesByField = [];
        }

        foreach ((array) $keys as $key => $value) {
            $field = \is_int($key) ? $value : $key;
            Assert::true(\array_key_exists((string) $field, $messagesByField), \sprintf(
                'Session is missing error for field [%s]. Available errors: %s.',
                $field,
                self::describe($messagesByField),
            ));

            if (\is_int($key)) {
                continue;
            }

            $messages = (array) ($messagesByField[$field] ?? []);

            Assert::contains(
                $messages,
                $value,
                \sprintf('Session error for field [%s] does not contain the expected message.', $field),
            );
        }

        return $this;
    }

    /**
     * Assert that the JSON at the given dot-path does not exist.
     *
     * `Arr::has()` distinguishes a missing key from a key holding `null`,
     * which `data_get()` cannot.
     *
     * @param non-empty-string $path
     */
    public function assertJsonMissingPath(string $path): static
    {
        Assert::false(
            \Illuminate\Support\Arr::has((array) $this->json(), $path),
            \sprintf('The JSON at path "%s" exists.', $path),
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

    public function assertFound(): static
    {
        return $this->assertStatus(302);
    }

    public function assertMethodNotAllowed(): static
    {
        return $this->assertStatus(405);
    }

    public function assertConflict(): static
    {
        return $this->assertStatus(409);
    }

    public function assertGone(): static
    {
        return $this->assertStatus(410);
    }

    public function assertInternalServerError(): static
    {
        return $this->assertStatus(500);
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
     * Assert that the response session contains a key or binding.
     *
     * @param non-empty-string|array<array-key, mixed> $key
     */
    public function assertSessionHas(string|array $key, mixed $value = null): static
    {
        if (\is_array($key)) {
            foreach ($key as $binding => $expected) {
                \is_int($binding)
                    ? $this->assertSessionHas((string) $expected)
                    : $this->assertSessionHas((string) $binding, $expected);
            }

            return $this;
        }

        $session = $this->session();
        Assert::true($session->has($key), \sprintf('Session is missing expected key [%s].', $key));

        if ($value instanceof \Closure) {
            Assert::true($value($session->get($key)), \sprintf(
                'Session value at [%s] did not satisfy the given closure.',
                $key,
            ));
        } elseif ($value !== null) {
            Assert::equals($session->get($key), $value, \sprintf(
                'Session value at [%s] does not match the expected value.',
                $key,
            ));
        }

        return $this;
    }

    /**
     * Assert that the response session does not contain a key or value.
     *
     * @param non-empty-string|list<non-empty-string> $key
     */
    public function assertSessionMissing(string|array $key, mixed $value = null): static
    {
        if (\is_array($key)) {
            foreach ($key as $item) {
                $this->assertSessionMissing($item);
            }

            return $this;
        }

        $session = $this->session();

        if ($value instanceof \Closure) {
            Assert::false($value($session->get($key)), \sprintf(
                'Session value at [%s] unexpectedly satisfied the given closure.',
                $key,
            ));
        } elseif ($value !== null) {
            Assert::notEquals($session->get($key), $value, \sprintf(
                'Session key [%s] unexpectedly has the given value.',
                $key,
            ));
        } else {
            Assert::false($session->has($key), \sprintf('Session has unexpected key [%s].', $key));
        }

        return $this;
    }

    /**
     * Assert that JSON validation errors exist for the requested fields.
     *
     * @param non-empty-string|array<array-key, mixed> $errors
     */
    public function assertJsonValidationErrors(string|array $errors, string $responseKey = 'errors'): static
    {
        $expected = Arr::wrap($errors);
        Assert::false($expected === [], 'No validation errors were provided.');

        $actual = Arr::get((array) $this->json(), $responseKey, []);
        Assert::true(\is_array($actual), 'The JSON validation error payload is not an array.');

        foreach ($expected as $key => $value) {
            $field = \is_int($key) ? $value : $key;
            Assert::true(Arr::has($actual, (string) $field), \sprintf(
                'The JSON response is missing a validation error for [%s].',
                $field,
            ));

            if (\is_int($key)) {
                continue;
            }

            foreach (Arr::wrap($value) as $message) {
                $matches = false;
                foreach (Arr::wrap(Arr::get($actual, (string) $field)) as $actualMessage) {
                    if (\str_contains((string) $actualMessage, (string) $message)) {
                        $matches = true;
                        break;
                    }
                }

                Assert::true($matches, \sprintf(
                    'The JSON validation error for [%s] does not contain [%s].',
                    $field,
                    $message,
                ));
            }
        }

        return $this;
    }

    /**
     * Assert that the original response view contains a bound value.
     */
    public function assertViewHas(string|array $key, mixed $value = null): static
    {
        if (\is_array($key)) {
            foreach ($key as $binding => $expected) {
                \is_int($binding)
                    ? $this->assertViewHas((string) $expected)
                    : $this->assertViewHas((string) $binding, $expected);
            }

            return $this;
        }

        $original = \method_exists($this->response, 'getOriginalContent')
            ? $this->response->getOriginalContent()
            : null;

        Assert::instanceOf($original, View::class, 'The response does not contain a view.');
        $data = $original->getData();
        $actual = Arr::get($data, $key);

        if ($value === null) {
            Assert::true(Arr::has($data, $key), \sprintf(
                'The response view is missing the key [%s].',
                $key,
            ));
        } elseif ($value instanceof \Closure) {
            Assert::true($value($actual), \sprintf(
                'The response view value at [%s] did not satisfy the given closure.',
                $key,
            ));
        } else {
            Assert::equals($actual, $value, \sprintf(
                'The response view value at [%s] does not match the expected value.',
                $key,
            ));
        }

        return $this;
    }

    /**
     * Return the session attached to a redirect response, or the active store.
     */
    public function getSession(): Session
    {
        return $this->session();
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

    private function session(): Session
    {
        if (\method_exists($this->response, 'getSession')) {
            $session = $this->response->getSession();
            if ($session instanceof Session) {
                if (!$session->isStarted()) {
                    $session->start();
                }

                return $session;
            }
        }

        $session = app('session.store');
        if (!$session->isStarted()) {
            $session->start();
        }

        return $session;
    }

    private static function absoluteLocation(string $uri): string
    {
        $container = \Illuminate\Container\Container::getInstance();

        return $container->bound('url')
            ? $container->make('url')->to($uri)
            : $uri;
    }
}
