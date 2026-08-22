<?php

declare(strict_types=1);

namespace Laratesto\Pipeline;

use Laratesto\Pipeline\Internal\FailureResult;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Runs user lifecycle hooks after database setup and before database cleanup.
 *
 * The ordering mirrors Laravel's PHPUnit TestCase: framework traits prepare
 * the database before the subclass setup hook, while the subclass teardown
 * hook runs before transactions and migrations are rolled back.
 *
 * @internal
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_DEFAULT + 50_000)]
final readonly class LaravelLifecycleInterceptor implements TestRunInterceptor
{
    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        try {
            $this->setUp($info);
        } catch (\Throwable $failure) {
            return FailureResult::fromLifecycle($info, $failure);
        }

        try {
            $result = $next($info);
        } catch (\Throwable $pipelineFailure) {
            $this->tearDownQuietly($info);
            throw $pipelineFailure;
        }

        try {
            $this->tearDown($info);
        } catch (\Throwable $failure) {
            return FailureResult::fromLifecycle($info, $failure);
        }

        return $result;
    }

    private function setUp(TestInfo $info): void
    {
        $instance = $this->instance($info);

        if ($instance !== null && \method_exists($instance, 'setUpLaravelApplication')) {
            $instance->setUpLaravelApplication();
        }
    }

    private function tearDown(TestInfo $info): void
    {
        $instance = $this->instance($info);

        if ($instance !== null && \method_exists($instance, 'tearDownLaravelApplication')) {
            $instance->tearDownLaravelApplication();
        }
    }

    private function tearDownQuietly(TestInfo $info): void
    {
        try {
            $this->tearDown($info);
        } catch (\Throwable) {
            // The original pipeline failure remains authoritative.
        }
    }

    private function instance(TestInfo $info): ?object
    {
        if ($info->caseInfo->definition->reflection === null || $info->caseInfo->instance === null) {
            return null;
        }

        return $info->caseInfo->instance->getInstance();
    }
}
