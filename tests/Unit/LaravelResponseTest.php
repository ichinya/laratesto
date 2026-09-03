<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Testing\AssertableJson;
use Laratesto\Testing\LaravelResponse;
use Symfony\Component\HttpFoundation\Response;
use Testo\Assert;
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
    public function assertJsonUsesRecursiveSubsetAndOptionalStrictScalarComparison(): void
    {
        $response = new LaravelResponse(new Response(
            '{"user":{"id":42,"name":"Ada"},"roles":["admin","editor"],"extra":true}',
        ));

        Assert::same($response->assertJson(['user' => ['id' => 42]]), $response);
        $response->assertJson(['user' => ['id' => '42']]);

        $strictFailed = false;
        try {
            $response->assertJson(['user' => ['id' => '42']], true);
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $strictFailed = true;
        }

        $missingFailed = false;
        try {
            $response->assertJson(['user' => ['missing' => true]]);
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $missingFailed = true;
        }

        Assert::true($strictFailed, 'Strict assertJson must not coerce scalar types.');
        Assert::true($missingFailed, 'Subset assertJson must reject a missing nested key.');
    }

    #[Test]
    public function assertHeaderMatchesNameCaseInsensitivelyButValueExactly(): void
    {
        $response = new LaravelResponse(new Response('', 200, ['X-Mode' => 'Production']));
        $response->assertHeader('x-mode', 'Production');

        $failed = false;
        try {
            $response->assertHeader('X-MODE', 'production');
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'Header values are case-sensitive even though header names are not.');
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

    // ---- Parity with Laravel's AssertableJsonString / TestResponse semantics ----

    #[Test]
    public function assertExactJsonIsAssociativeKeyOrderInsensitive(): void
    {
        // M6: Laravel canonicalizes associative key order before comparing.
        $response = new LaravelResponse(new Response('{"b":2,"a":1}'));
        $response->assertExactJson(['a' => 1, 'b' => 2]);
        $response->assertExactJson(['b' => 2, 'a' => 1]);

        $nested = new LaravelResponse(new Response('{"user":{"name":"Ada","id":42},"meta":[1,2]}'));
        $nested->assertExactJson(['meta' => [1, 2], 'user' => ['id' => 42, 'name' => 'Ada']]);

        // List order must stay significant.
        $list = new LaravelResponse(new Response('[1,2]'));

        $failed = false;
        try {
            $list->assertExactJson([2, 1]);
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertExactJson must remain order-sensitive for lists.');
    }

    #[Test]
    public function assertJsonSupportsLaravelFluentClosureForm(): void
    {
        $response = new LaravelResponse(new Response('{"id":1,"name":"Ada"}'));

        $response->assertJson(static function (AssertableJson $json): void {
            $json->where('id', 1)
                ->where('name', 'Ada')
                ->etc();
        });

        $response->assertJson(static fn (AssertableJson $json) => $json->has('id')->etc());

        // A failing where() must surface as a Testo assertion failure.
        $failed = false;
        try {
            $response->assertJson(static fn (AssertableJson $json) => $json->where('id', 2));
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'Fluent assertJson must fail when a where() assertion does not match.');

        // Without ->etc(), untouched properties on an assoc root must fail the interaction check.
        $strict = false;
        try {
            $response->assertJson(static fn (AssertableJson $json) => $json->where('id', 1));
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $strict = true;
        }

        Assert::true($strict, 'Fluent assertJson must require ->etc() or full interaction on assoc roots.');
    }

    #[Test]
    public function assertSeeAcceptsArraysAndEscapesLikeLaravelE(): void
    {
        $response = new LaravelResponse(new Response('hello <b>world</b> &amp; more'));

        // Laravel accepts arrays of needles.
        $response->assertSee(['hello', 'world']);
        $response->assertSee('&amp;', escape: false);

        // A raw "<b>" body must NOT satisfy the pre-escaped needle.
        $failed = false;
        try {
            $response->assertSee('&lt;b&gt;');
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertSee must keep pre-escaped needles encoded (Laravel e() default).');

        // Laravel's e() double-encodes, so a pre-escaped needle never matches a
        // body that literally contains the pre-escaped sequence.
        $escaped = new LaravelResponse(new Response('shows &lt;b&gt; as text'));

        $failed = false;
        try {
            $escaped->assertSee('&lt;b&gt;');
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertSee must double-encode needles (Laravel e() default).');
    }

    #[Test]
    public function assertDontSeeEscapesLikeLaravelE(): void
    {
        // A body that literally contains the pre-escaped sequence must still satisfy
        // assertDontSee: Laravel's e() double-encodes the needle before searching.
        $response = new LaravelResponse(new Response('commit &lt;script&gt; alert'));
        $response->assertDontSee('&lt;script&gt;');

        // A pre-escaped needle must not be decoded into its raw form either.
        $raw = new LaravelResponse(new Response('<script>alert(1)</script> safe'));
        $raw->assertDontSee('&lt;script&gt;');

        $other = new LaravelResponse(new Response('plain text'));

        $failed = false;
        try {
            $other->assertDontSee(['plain', 'missing']);
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertDontSee must fail when any array needle is present.');
    }

    #[Test]
    public function assertJsonPathAppliesEnumValueToExpectations(): void
    {
        $response = new LaravelResponse(new Response('{"status":"active"}'));

        $response->assertJsonPath('status', ParityStatus::Active);

        $failed = false;
        try {
            $response->assertJsonPath('status', ParityStatus::Archived);
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertJsonPath must fail for a non-matching backed enum expectation.');
    }

    #[Test]
    public function assertSessionHasErrorsSupportsErrorBagAndFormat(): void
    {
        $store = new \Illuminate\Session\Store('parity', new \Illuminate\Session\ArraySessionHandler(120));
        $store->start();

        $bag = new \Illuminate\Support\ViewErrorBag();
        $bag->put('default', new \Illuminate\Support\MessageBag([
            'email' => ['The email field is required.'],
        ]));
        $bag->put('custom', new \Illuminate\Support\MessageBag([
            'name' => ['The name field is required.'],
        ]));
        $store->put('errors', $bag);

        $response = new LaravelResponse(new class($store) extends Response {
            public function __construct(
                private readonly \Illuminate\Session\Store $store,
            ) {
                parent::__construct('');
            }

            public function getSession(): \Illuminate\Session\Store
            {
                return $this->store;
            }
        });

        $response->assertSessionHasErrors(['email']);
        $response->assertSessionHasErrors(['email' => 'The email field is required.']);

        // Laravel's named-argument form for a non-default bag.
        $response->assertSessionHasErrors(['name'], errorBag: 'custom');

        // Laravel threads $format through the message comparison.
        $response->assertSessionHasErrors(
            ['email' => '<p>The email field is required.</p>'],
            format: '<p>:message</p>',
        );

        // The error lives in the "custom" bag, not the default one.
        $failed = false;
        try {
            $response->assertSessionHasErrors(['name']);
        } catch (\Testo\Assert\State\Assertion\AssertionException) {
            $failed = true;
        }

        Assert::true($failed, 'assertSessionHasErrors must only consider the requested error bag.');
    }
}

enum ParityStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
