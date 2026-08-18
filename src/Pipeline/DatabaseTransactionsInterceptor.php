<?php

declare(strict_types=1);

namespace Testo\Bridge\Laravel\Pipeline;

use Testo\Bridge\Laravel\Attribute\DatabaseTransactions;
use Testo\Bridge\Laravel\Runtime\LaravelApplicationFactory;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Wraps a test into a database transaction and rolls it back afterwards.
 *
 * Runs inside {@see LaravelTestInterceptor}, so the application is already booted.
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
        $manager = $this->factory->current()->make('db');

        $manager->beginTransaction();

        try {
            return $next($info);
        } finally {
            if ($manager->transactionLevel() > 0) {
                $manager->rollBack();
            }
        }
    }
}
