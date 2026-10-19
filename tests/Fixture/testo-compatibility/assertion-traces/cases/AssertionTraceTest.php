<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\AssertionTraces;

use Laratesto\Testing\AssertableJson;
use Laratesto\Testing\LaravelResponse;
use Laratesto\Testing\LaravelTestCase;
use Laratesto\Testing\PhpUnitCompatibility;
use Symfony\Component\HttpFoundation\Response;
use Testo\Assert;
use Testo\Test;

#[Test]
final class AssertionTraceTest extends LaravelTestCase
{
    public function httpShortcut(): void
    {
        (new LaravelResponse(new Response('', 500)))->assertOk(); // trace: httpShortcut
    }

    public function jsonPath(): void
    {
        (new LaravelResponse(new Response('{"id":1}')))->assertJsonPath('id', 2); // trace: jsonPath
    }

    public function fluentJson(): void
    {
        (new LaravelResponse(new Response('{"user":{"id":1}}')))->assertJson(function (AssertableJson $json): void {
            $json->has('user', function (AssertableJson $user): void {
                $user->where('id', 2); // trace: fluentJson
            });
        });
    }

    public function firstScope(): void
    {
        AssertableJson::fromArray([['id' => 1]])->first(function (AssertableJson $json): void {
            $json->where('id', 2); // trace: firstScope
        });
    }

    public function eachScope(): void
    {
        AssertableJson::fromArray([['id' => 1]])->each(function (AssertableJson $json): void {
            $json->where('id', 2); // trace: eachScope
        });
    }

    public function hasAllScope(): void
    {
        AssertableJson::fromArray(['user' => ['id' => 1]])->hasAll(['user' => function (AssertableJson $json): void {
            $json->where('id', 2); // trace: hasAllScope
        }]);
    }

    public function callbackAssertion(): void
    {
        (new LaravelResponse(new Response('{"id":1}')))->assertJson(function (AssertableJson $json): void {
            Assert::same(1, 2); // trace: callbackAssertion
        });
    }

    public function callbackException(): void
    {
        (new LaravelResponse(new Response('{"id":1}')))->assertJson(function (AssertableJson $json): void {
            throw new \RuntimeException('User callback exception'); // trace: callbackException
        });
    }

    public function delegatedJson(): void
    {
        (new LaravelResponse(new Response('[1]')))->assertJsonCount(2); // trace: delegatedJson
    }

    public function shim(): void
    {
        \PHPUnit\Framework\Assert::assertSame(1, 2); // trace: shim
    }

    public function compatibility(): void
    {
        PhpUnitCompatibility::assertStringContainsString('missing', 'actual'); // trace: compatibility
    }

    public function authentication(): void
    {
        $this->assertAuthenticated(); // trace: authentication
    }

    public function artisanFailure(): void
    {
        $this->artisan('list')->assertFailed(); // trace: artisanFailure
    }

    public function deferredRun(): void
    {
        $pending = $this->pendingArtisan('list')->assertExitCode(42);
        $pending->run(); // trace: deferredRun
    }

    public function deferredExecute(): void
    {
        $pending = $this->pendingArtisan('list')->assertExitCode(42);
        $pending->execute(); // trace: deferredExecute
    }

    public function successfulAssertions(): void
    {
        $response = new LaravelResponse(new Response('{"id":1}'));
        Assert::same($response, $response->assertOk()->assertJsonPath('id', 1));
        $json = AssertableJson::fromArray(['id' => 1]);
        Assert::same($json, $json->where('id', 1)->etc());
    }
}
