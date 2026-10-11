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
    public function __construct(private ?\Testo\Common\Messenger $messenger = null) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        try {
            $this->setUp($info);
        } catch (\Throwable $failure) {
            $instance = $this->instance($info);
            if ($instance !== null && method_exists($instance, 'clearLaravelApplicationReferences')) {
                try {
                    $instance->clearLaravelApplicationReferences();
                } catch (\Throwable $cleanupFailure) {
                    FailureResult::report($cleanupFailure, 'partial setup reference cleanup', $this->messenger);
                }
            }
            return FailureResult::fromLifecycle($info, $failure);
        }

        try {
            $result = $next($info);
        } catch (\Throwable $pipelineFailure) {
            $this->tearDownQuietly($info);
            throw $pipelineFailure;
        }

        try {
            $instance = $this->instance($info);
            if ($instance !== null && method_exists($instance, 'finishPhpUnitOutputCapture')) {
                $instance->finishPhpUnitOutputCapture($result->status === \Testo\Core\Value\Status::Passed);
            }
        } catch (\Throwable $failure) {
            $result = FailureResult::afterCleanup($result, $failure, 'output assertions', $this->messenger, lifecycle: true);
        }

        try {
            $this->tearDown($info);
        } catch (\Throwable $failure) {
            return FailureResult::afterCleanup($result, $failure, 'Laravel teardown', $this->messenger, lifecycle: true);
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
        } catch (\Throwable $failure) {
            FailureResult::report($failure, 'Laravel teardown', $this->messenger);
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
