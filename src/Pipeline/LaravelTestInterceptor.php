<?php

declare(strict_types=1);

namespace Testo\Bridge\Laravel\Pipeline;

use Testo\Bridge\Laravel\Runtime\LaravelApplicationFactory;
use Testo\Bridge\Laravel\Runtime\LaravelStateCleaner;
use Testo\Bridge\Laravel\Testing\LaravelApplicationAware;
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
        $application = $this->factory->boot();

        $this->injectApplication($info, $application);

        try {
            return $next($info);
        } finally {
            $this->cleaner->clean($application);
            $this->factory->flush();
        }
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
}
