<?php

declare(strict_types=1);

namespace Laratesto\Pipeline;

use Laratesto\Attribute\DatabaseTransactions;
use Laratesto\Pipeline\Internal\FailureResult;
use Laratesto\Runtime\LaravelApplicationFactory;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Wraps a test into a database transaction and rolls it back afterwards.
 *
 * Runs inside {@see LaravelTestInterceptor}, so the application is already booted.
 * Transaction failures are returned as aborted test results carrying the original
 * exception instead of being thrown (see {@see FailureResult} for why).
 *
 * @see DatabaseTransactions
 *
 * @api
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_DEFAULT)]
final readonly class DatabaseTransactionsInterceptor implements TestRunInterceptor
{
    public function __construct(
        private LaravelApplicationFactory $factory,
    ) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        try {
            $manager = $this->factory->current()->make('db');
            $manager->beginTransaction();
        } catch (\Throwable $failure) {
            return FailureResult::aborted($info, $failure);
        }

        try {
            $result = $next($info);
        } catch (\Throwable $pipelineFailure) {
            // Roll back quietly: the original failure is more useful
            // than a rollback error on top of it.
            try {
                $manager->transactionLevel() > 0 and $manager->rollBack();
            } catch (\Throwable) {
                // ignored on purpose
            }

            throw $pipelineFailure;
        }

        if ($manager->transactionLevel() > 0) {
            try {
                $manager->rollBack();
            } catch (\Throwable $rollbackFailure) {
                return FailureResult::aborted($info, $rollbackFailure);
            }
        }

        return $result;
    }
}
