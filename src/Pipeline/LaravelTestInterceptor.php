<?php

declare(strict_types=1);

namespace Laratesto\Pipeline;

use Laratesto\Pipeline\Internal\FailureResult;
use Laratesto\Runtime\LaravelApplicationFactory;
use Laratesto\Runtime\LaravelStateCleaner;
use Laratesto\Testing\LaravelApplicationAware;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Boots the Laravel application before every test and cleans up after it.
 *
 * The interceptor is ordered to run before attribute interceptors (RefreshDatabase,
 * DatabaseTransactions), so those always see a booted application.
 *
 * Bridge failures (boot, injection, cleanup) are returned as aborted test
 * results carrying the original exception instead of being thrown: a thrown
 * interceptor exception would be wrapped by Testo into an opaque
 * `PipelineFailure` that hides the root cause in the failure output.
 *
 * @api
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_DEFAULT - 100_000)]
final readonly class LaravelTestInterceptor implements TestRunInterceptor
{
    public function __construct(
        private LaravelApplicationFactory $factory,
        private LaravelStateCleaner $cleaner,
    ) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        try {
            $application = $this->factory->boot();
        } catch (\Throwable $bootFailure) {
            return FailureResult::aborted($info, $bootFailure);
        }

        try {
            $this->injectApplication($info, $application);
        } catch (\Throwable $injectionFailure) {
            $this->cleanupQuietly($application);
            return FailureResult::aborted($info, $injectionFailure);
        }

        try {
            $result = $next($info);
        } catch (\Throwable $pipelineFailure) {
            // Not a bridge failure: let the runner classify it. Cleanup errors
            // are secondary to whatever the pipeline already reported.
            $this->cleanupQuietly($application);
            throw $pipelineFailure;
        }

        try {
            $this->cleaner->clean($application);
        } catch (\Throwable $cleanupFailure) {
            $this->factory->flush();
            return FailureResult::aborted($info, $cleanupFailure);
        }

        $this->factory->flush();

        return $result;
    }

    /**
     * Provide the booted application to test case instances that ask for it:
     * either via the {@see LaravelApplicationAware} interface or simply by using
     * the {@see InteractsWithLaravel} trait, which provides the setter method.
     */
    private function injectApplication(TestInfo $info, \Illuminate\Contracts\Foundation\Application $application): void
    {
        $definition = $info->caseInfo->definition;

        if ($definition->reflection === null
            || $info->caseInfo->instance === null
        ) {
            return;
        }

        $instance = $info->caseInfo->instance->getInstance();

        if ($instance instanceof LaravelApplicationAware) {
            $instance->setLaravelApplication($application);
            return;
        }

        if (\method_exists($instance, 'setLaravelApplication')) {
            $instance->setLaravelApplication($application);
        }
    }

    /**
     * Best-effort cleanup used when the test or pipeline already failed:
     * the original failure is more useful than a cleanup error.
     */
    private function cleanupQuietly(\Illuminate\Contracts\Foundation\Application $application): void
    {
        try {
            $this->cleaner->clean($application);
        } catch (\Throwable) {
            // ignored on purpose
        }

        $this->factory->flush();
    }
}
