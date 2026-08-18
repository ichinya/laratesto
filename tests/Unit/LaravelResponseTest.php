<?php

declare(strict_types=1);

namespace Testo\Bridge\Laravel\Tests\Unit;

use Symfony\Component\HttpFoundation\Response;
use Testo\Assert;
use Testo\Bridge\Laravel\Testing\LaravelResponse;
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
        Assert::same($response->header('X-Test'), 'yes');
        Assert::null($response->header('X-Missing'));
        Assert::same($response->header('X-Missing', 'fallback'), 'fallback');
        Assert::same($response->body(), '{"ok": true}');
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
}
