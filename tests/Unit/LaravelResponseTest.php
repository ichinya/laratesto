<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Symfony\Component\HttpFoundation\Response;
use Testo\Assert;
use Laratesto\Testing\LaravelResponse;
use Testo\Test;

final class LaravelResponseTest
{
    #[Test]
    public function exposesStatusHeadersAndBody(): void
    {
        $response = new LaravelResponse(
            new Response('{"ok": true}', 201, ['X-Test' => 'yes']),
        );

        Assert::same($response->status(), 201);
        Assert::same($response->getStatusCode(), 201);
        Assert::same($response->header('X-Test'), 'yes');
        Assert::same($response->headers->get('X-Test'), 'yes');
        Assert::null($response->header('X-Missing'));
        Assert::same($response->header('X-Missing', 'fallback'), 'fallback');
        Assert::same($response->body(), '{"ok": true}');
        Assert::same($response->getContent(), '{"ok": true}');
        Assert::same($response->json(), ['ok' => true]);
    }

    #[Test]
    public function jsonThrowsOnInvalidPayload(): void
    {
        $response = new LaravelResponse(new Response('not json'));

        $failed = false;

        try {
            $response->json();
        } catch (\RuntimeException) {
            $failed = true;
        }

        Assert::true($failed, 'json() must throw a RuntimeException for a non-JSON body.');
    }

    #[Test]
    public function assertExactJsonMatchesTheWholeDecodedPayload(): void
    {
        $response = new LaravelResponse(new Response('{"created":true}', 201));

        Assert::same($response->assertExactJson(['created' => true]), $response);

        $failed = false;

        try {
            $response->assertExactJson(['created' => true, 'extra' => false]);
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertExactJson must reject a non-identical JSON payload.');
    }

    #[Test]
    public function assertionsReturnTheResponseForChaining(): void
    {
        $response = new LaravelResponse(
            new Response('hello', 200, ['Content-Type' => 'text/plain']),
        );

        $result = $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain')
            ->assertSee('hello');

        Assert::same($result, $response);
    }

    // ---- Status shortcuts ----

    #[Test]
    public function assertCreated(): void
    {
        $response = new LaravelResponse(new Response('', 201));
        $response->assertCreated();
        // no exception = pass
    }

    #[Test]
    public function assertBadRequest(): void
    {
        $response = new LaravelResponse(new Response('', 400));
        $response->assertBadRequest();
    }

    #[Test]
    public function assertUnauthorized(): void
    {
        $response = new LaravelResponse(new Response('', 401));
        $response->assertUnauthorized();
    }

    #[Test]
    public function assertForbidden(): void
    {
        $response = new LaravelResponse(new Response('', 403));
        $response->assertForbidden();
    }

    #[Test]
    public function assertNotFound(): void
    {
        $response = new LaravelResponse(new Response('', 404));
        $response->assertNotFound();
    }

    #[Test]
    public function assertUnprocessable(): void
    {
        $response = new LaravelResponse(new Response('', 422));
        $response->assertUnprocessable();
    }

    // ---- assertDontSee ----

    #[Test]
    public function assertDontSeeOk(): void
    {
        $response = new LaravelResponse(new Response('hello world'));
        $response->assertDontSee('nope');

        $response = new LaravelResponse(new Response('hello world'));
        $response->assertDontSee(['x', 'y']);
        // pass
    }

    #[Test]
    public function assertDontSeeFails(): void
    {
        $response = new LaravelResponse(new Response('hello world'));

        $failed = false;

        try {
            $response->assertDontSee('hello');
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertDontSee must fail when the text is present.');
    }

    // ---- assertRedirect ----

    #[Test]
    public function assertRedirectOk(): void
    {
        $response = new LaravelResponse(new Response('', 302, ['Location' => '/login']));
        $response->assertRedirect();
        $response->assertRedirect('/login');
    }

    #[Test]
    public function assertRedirectFailsOnNonRedirect(): void
    {
        $response = new LaravelResponse(new Response('ok', 200));

        $failed = false;

        try {
            $response->assertRedirect();
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertRedirect must fail on a 200 response.');
    }

    #[Test]
    public function assertRedirectFailsOnWrongUri(): void
    {
        $response = new LaravelResponse(new Response('', 302, ['Location' => '/login']));

        $failed = false;

        try {
            $response->assertRedirect('/dashboard');
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertRedirect must fail on a mismatched Location.');
    }

    // ---- assertHeaderMissing ----

    #[Test]
    public function assertHeaderMissingOk(): void
    {
        $response = new LaravelResponse(new Response(''));
        $response->assertHeaderMissing('X-Nonexistent');
    }

    #[Test]
    public function assertHeaderMissingFails(): void
    {
        $response = new LaravelResponse(new Response('', 200, ['X-Test' => 'yes']));

        $failed = false;

        try {
            $response->assertHeaderMissing('X-Test');
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed);
    }

    // ---- assertJsonPath ----

    #[Test]
    public function assertJsonPathScalar(): void
    {
        $response = new LaravelResponse(new Response('{"user": {"id": 42, "name": "Alice"}}', 200));
        $response->assertJsonPath('user.id', 42);
        $response->assertJsonPath('user.name', 'Alice');
    }

    #[Test]
    public function assertJsonPathClosure(): void
    {
        $response = new LaravelResponse(new Response('{"items": [1, 2, 3]}', 200));
        $response->assertJsonPath('items', static fn (array $items): bool => \count($items) === 3);
    }

    #[Test]
    public function assertJsonPathNull(): void
    {
        $response = new LaravelResponse(new Response('{"meta": null}', 200));
        $response->assertJsonPath('meta', null);
    }

    #[Test]
    public function assertJsonPathFailsOnMismatch(): void
    {
        $response = new LaravelResponse(new Response('{"user": {"id": 42}}', 200));

        $failed = false;

        try {
            $response->assertJsonPath('user.id', 99);
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed);
    }

    // ---- assertJsonStructure ----

    #[Test]
    public function assertJsonStructureFlat(): void
    {
        $response = new LaravelResponse(new Response('{"id": 1, "name": "test"}', 200));
        $response->assertJsonStructure(['id', 'name']);
    }

    #[Test]
    public function assertJsonStructureNested(): void
    {
        $response = new LaravelResponse(new Response('{"user": {"id": 1, "email": "a@b.c"}}', 200));
        $response->assertJsonStructure(['user' => ['id', 'email']]);
    }

    #[Test]
    public function assertJsonStructureWildcard(): void
    {
        $json = '{"items": [{"id": 1, "name": "a"}, {"id": 2, "name": "b"}]}';
        $response = new LaravelResponse(new Response($json, 200));
        $response->assertJsonStructure(['items' => ['*' => ['id', 'name']]]);
    }

    #[Test]
    public function assertJsonMissingPath(): void
    {
        $response = new LaravelResponse(new Response('{"a": {"b": 1}, "n": null}', 200));

        // Missing nested path
        $response->assertJsonMissingPath('a.b.c');
        // Missing top-level key
        $response->assertJsonMissingPath('missing');

        // A key holding null EXISTS and must not pass as missing
        $failed = false;

        try {
            $response->assertJsonMissingPath('n');
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertJsonMissingPath must fail when the key exists with a null value.');
    }

    #[Test]
    public function assertJsonStructureFailsOnMissingKey(): void
    {
        $response = new LaravelResponse(new Response('{"id": 1}', 200));

        $failed = false;

        try {
            $response->assertJsonStructure(['id', 'name']);
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed);
    }
}
