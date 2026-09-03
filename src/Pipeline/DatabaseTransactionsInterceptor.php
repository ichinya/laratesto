<?php

declare(strict_types=1);

namespace Laratesto\Pipeline;

use Laratesto\Attribute\DatabaseTransactions;
use Laratesto\Pipeline\Internal\DatabaseRuntime;
use Laratesto\Pipeline\Internal\DatabaseTransactionScope;
use Laratesto\Pipeline\Internal\FailureResult;
use Laratesto\Runtime\LaravelApplicationFactory;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/** Wraps every configured connection and guarantees complete rollback cleanup. */
#[InterceptorOptions(order: InterceptorOptions::ORDER_DEFAULT)]
final readonly class DatabaseTransactionsInterceptor implements TestRunInterceptor
{
    public function __construct(
        private DatabaseTransactions $attribute,
        private LaravelApplicationFactory $factory,
    ) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        try {
            $application = $this->factory->current();
            $connections = DatabaseRuntime::connectionNames($application, $this->attribute->connections);

            // In-memory databases must survive the per-test application rebuilds: restore
            // the cached connections so the transaction actually wraps migrated data.
            DatabaseRuntime::restoreInMemoryConnections($application, $connections);

            $scope = new DatabaseTransactionScope($application, $connections);
            $scope->begin();
        } catch (\Throwable $failure) {
            return FailureResult::aborted($info, $failure);
        }

        try {
            $result = $next($info);
        } catch (\Throwable $pipelineFailure) {
            $scope->closeQuietly();

            try {
                DatabaseRuntime::cacheInMemoryConnections($application, $connections);
            } catch (\Throwable) {
                // The original pipeline failure remains authoritative.
            }

            throw $pipelineFailure;
        }

        try {
            $scope->close();
        } catch (\Throwable $failure) {
            return FailureResult::aborted($info, $failure);
        }

        try {
            DatabaseRuntime::cacheInMemoryConnections($application, $connections);
        } catch (\Throwable $failure) {
            return FailureResult::aborted($info, $failure);
        }

        return $result;
    }
}
