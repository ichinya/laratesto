<?php

declare(strict_types=1);

namespace Laratesto\Pipeline;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Laratesto\Attribute\RefreshDatabase;
use Laratesto\Pipeline\Internal\FailureResult;
use Laratesto\Runtime\LaravelApplicationFactory;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Runs `migrate:fresh` before the test.
 *
 * Ordered before {@see DatabaseTransactionsInterceptor}, so a test may combine
 * both attributes: fresh migrations first, then the wrapping transaction.
 *
 * Migration failures are returned as aborted test results carrying the original
 * exception instead of being thrown (see {@see FailureResult} for why).
 *
 * @see RefreshDatabase
 *
 * @api
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_DEFAULT - 50_000)]
final readonly class RefreshDatabaseInterceptor implements TestRunInterceptor
{
    public function __construct(
        private RefreshDatabase $attribute,
        private LaravelApplicationFactory $factory,
    ) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        try {
            $this->migrateFresh();
        } catch (\Throwable $failure) {
            return FailureResult::aborted($info, $failure);
        }

        return $next($info);
    }

    private function migrateFresh(): void
    {
        $kernel = $this->factory->current()->make(ConsoleKernel::class);

        $parameters = ['--force' => true];

        if ($this->attribute->seed) {
            $parameters['--seed'] = true;
        }

        $exitCode = $kernel->call('migrate:fresh', $parameters);

        if ($exitCode !== 0) {
            throw new \RuntimeException(\sprintf(
                'migrate:fresh failed with exit code %d: %s',
                $exitCode,
                $kernel->output(),
            ));
        }
    }
}
