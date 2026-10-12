<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Analysis;

use Laratesto\Rector\Analysis\HttpCompatibilityAnalyzer;
use Laratesto\Testing\InteractsWithLaravel;
use Laratesto\Testing\PendingArtisanCommand;
use ReflectionClass;
use Testo\Assert;
use Testo\Test;

/**
 * Runtime-parity contract for the `$this->` call matrices
 * (HttpCompatibilityAnalyzer::REQUEST_SIGNATURES / HELPER_SIGNATURES) and the
 * pending Artisan matrix (PENDING_ARTISAN_SIGNATURES), the helper-matrix
 * sibling of ResponseMatrixRuntimeContractTest.
 *
 * Pins the direction the migration gate depends on so a helper signature can
 * never silently under-accept again: the recent drift declared
 * `assertExitCode` as 1..3 arguments while the real runtime helper
 * (InteractsWithLaravel::assertExitCode(int $code, string $command,
 * array $parameters = [])) requires two, so a one-argument call passed the
 * preflight and died with an ArgumentCountError after migration.
 *
 * 1. every listed method exists on the real runtime class;
 * 2. argument bounds match the real runtime parameter counts in BOTH
 *    directions: minimum >= the runtime's required count (no call that would
 *    TypeError survives the preflight) and maximum <= the runtime's total
 *    parameter count (no impossible arity is whitelisted);
 * 3. the pending Artisan matrix mirrors PendingArtisanCommand exactly — its
 *    assertExitCode() takes ONE argument, the opposite of the test-case
 *    helper, and the two matrices must never merge.
 *
 * The omission direction stays unpinned on purpose: helpers absent from the
 * matrices fail the preflight closed (safe over-blocking), while a wrong
 * bound fails closed at runtime instead.
 */
final class HelperMatrixRuntimeContractTest
{
    #[Test]
    public function everyListedCallMatrixMethodExistsOnTheRuntime(): void
    {
        $runtimeMethods = $this->runtimeMethods(InteractsWithLaravel::class);

        foreach (['REQUEST_SIGNATURES', 'HELPER_SIGNATURES'] as $constant) {
            foreach ($this->signatures($constant) as $method => $signature) {
                Assert::true(
                    \in_array($method, $runtimeMethods, true),
                    \sprintf('%s lists %s(), but Laratesto\Testing\InteractsWithLaravel does not implement it.', $constant, $method),
                );
            }
        }
    }

    #[Test]
    public function callMatrixBoundsMatchRuntimeSignatures(): void
    {
        $runtime = new ReflectionClass(InteractsWithLaravel::class);

        foreach (['REQUEST_SIGNATURES', 'HELPER_SIGNATURES'] as $constant) {
            foreach ($this->signatures($constant) as $method => $signature) {
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
                        '%s declares %s() as %d..%d arguments, but the runtime signature accepts %d..%d.',
                        $constant,
                        $method,
                        $minimum,
                        $maximum,
                        $required,
                        \count($parameters),
                    ),
                );
            }
        }
    }

    #[Test]
    public function everyListedPendingArtisanMethodExistsOnTheRuntime(): void
    {
        $runtimeMethods = $this->runtimeMethods(PendingArtisanCommand::class);

        foreach ($this->signatures('PENDING_ARTISAN_SIGNATURES') as $method => $signature) {
            Assert::true(
                \in_array($method, $runtimeMethods, true),
                \sprintf('PENDING_ARTISAN_SIGNATURES lists %s(), but Laratesto\Testing\PendingArtisanCommand does not implement it.', $method),
            );
        }
    }

    #[Test]
    public function pendingArtisanBoundsMatchRuntimeSignatures(): void
    {
        $runtime = new ReflectionClass(PendingArtisanCommand::class);

        foreach ($this->signatures('PENDING_ARTISAN_SIGNATURES') as $method => $signature) {
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
                    'PENDING_ARTISAN_SIGNATURES declares %s() as %d..%d arguments, but the runtime signature accepts %d..%d.',
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
    private function signatures(string $constant): array
    {
        return (new ReflectionClass(HttpCompatibilityAnalyzer::class))->getConstant($constant);
    }

    /**
     * @return list<non-empty-string>
     */
    private function runtimeMethods(string $class): array
    {
        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            $methods[] = $method->getName();
        }

        return $methods;
    }
}
