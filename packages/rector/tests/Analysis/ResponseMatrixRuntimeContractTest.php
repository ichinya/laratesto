<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Analysis;

use Laratesto\Rector\Analysis\HttpCompatibilityAnalyzer;
use Laratesto\Testing\LaravelResponse;
use ReflectionClass;
use ReflectionNamedType;
use Testo\Assert;
use Testo\Test;

/**
 * Runtime-parity contract for the response compatibility matrix
 * (HttpCompatibilityAnalyzer::RESPONSE_SIGNATURES / RESPONSE_FLUENT_METHODS).
 *
 * Pins the directions the migration gate depends on so a future runtime
 * assert cannot be silently over-blocked again (RESPONSE_UNSUPPORTED_API
 * false residuals) and a runtime-only helper cannot sneak into the matrix:
 *
 * 1. every listed method exists on Laratesto\Testing\LaravelResponse;
 * 2. every assert Laratesto implements that Laravel's TestResponse also
 *    declares is listed — the omission class behind the assertSee /
 *    assertDontSee / status-shortcut / session-view-json matrix fixes;
 * 3. every listed assert is genuinely shared with Laravel's TestResponse;
 * 4. the fluent list stays in exact lockstep with static-returning entries;
 * 5. argument bounds match the real runtime parameter counts.
 */
final class ResponseMatrixRuntimeContractTest
{
    #[Test]
    public function everyListedResponseMethodExistsOnTheRuntime(): void
    {
        $runtimeMethods = $this->runtimeMethods();

        foreach ($this->signatures() as $method => $signature) {
            Assert::true(
                \in_array($method, $runtimeMethods, true),
                \sprintf('RESPONSE_SIGNATURES lists %s(), but Laratesto\Testing\LaravelResponse does not implement it.', $method),
            );
        }
    }

    #[Test]
    public function everySharedRuntimeAssertIsListed(): void
    {
        $laravelMethods = $this->laravelTestResponseMethods();
        $listed = \array_keys($this->signatures());

        foreach ($this->runtimeMethods() as $method) {
            if (! \str_starts_with($method, 'assert') || ! \in_array($method, $laravelMethods, true)) {
                continue;
            }

            Assert::true(
                \in_array($method, $listed, true),
                \sprintf(
                    'LaravelResponse::%s() is also declared by Laravel TestResponse, but RESPONSE_SIGNATURES omits it; migrated tests would be over-blocked with RESPONSE_UNSUPPORTED_API.',
                    $method,
                ),
            );
        }
    }

    #[Test]
    public function everyListedAssertIsSharedWithLaravelTestResponse(): void
    {
        $laravelMethods = $this->laravelTestResponseMethods();

        foreach ($this->signatures() as $method => $signature) {
            if (! \str_starts_with($method, 'assert')) {
                continue;
            }

            Assert::true(
                \in_array($method, $laravelMethods, true),
                \sprintf('RESPONSE_SIGNATURES lists %s(), but Laravel TestResponse never declares it; the matrix must not whitelist runtime-only helpers.', $method),
            );
        }
    }

    #[Test]
    public function fluentListMirrorsStaticReturningSignatures(): void
    {
        $runtime = new ReflectionClass(LaravelResponse::class);
        $staticReturning = [];

        foreach (\array_keys($this->signatures()) as $method) {
            $returnType = $runtime->getMethod($method)->getReturnType();

            $isStatic = $returnType instanceof ReflectionNamedType && $returnType->getName() === 'static';

            if ($isStatic) {
                $staticReturning[] = $method;
            }
        }

        Assert::same($this->fluentMethods(), $staticReturning);
    }

    #[Test]
    public function argumentBoundsMatchRuntimeSignatures(): void
    {
        $runtime = new ReflectionClass(LaravelResponse::class);

        foreach ($this->signatures() as $method => $signature) {
            [$minimum, $maximum] = $signature;
            $parameters = $runtime->getMethod($method)->getParameters();
            $required = 0;

            foreach ($parameters as $parameter) {
                if (! $parameter->isOptional()) {
                    ++$required;
                }
            }

            Assert::true(
                $required <= $minimum && $minimum <= $maximum && $maximum <= \count($parameters),
                \sprintf(
                    'RESPONSE_SIGNATURES declares %s() as %d..%d arguments, but the runtime signature accepts %d..%d.',
                    $method,
                    $minimum,
                    $maximum,
                    $required,
                    \count($parameters),
                ),
            );
        }
    }

    /**
     * @return array<string, array{int, int, list<non-empty-string>}>
     */
    private function signatures(): array
    {
        return (new ReflectionClass(HttpCompatibilityAnalyzer::class))->getConstant('RESPONSE_SIGNATURES');
    }

    /**
     * @return list<non-empty-string>
     */
    private function fluentMethods(): array
    {
        return (new ReflectionClass(HttpCompatibilityAnalyzer::class))->getConstant('RESPONSE_FLUENT_METHODS');
    }

    /**
     * @return list<non-empty-string>
     */
    private function runtimeMethods(): array
    {
        $methods = [];

        foreach ((new ReflectionClass(LaravelResponse::class))->getMethods() as $method) {
            if (! $method->isConstructor()) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    /**
     * TestResponse has no parent class, so getMethods() is exactly its declared
     * surface: own methods plus every method pulled in by its traits (including
     * the AssertsStatusCodes shortcuts).
     *
     * @return list<non-empty-string>
     */
    private function laravelTestResponseMethods(): array
    {
        $methods = [];

        foreach ((new ReflectionClass(\Illuminate\Testing\TestResponse::class))->getMethods() as $method) {
            $methods[] = $method->getName();
        }

        return $methods;
    }
}
