<?php

declare(strict_types=1);

namespace Laratesto\Pipeline;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Laratesto\Attribute\RefreshDatabase;
use Laratesto\Pipeline\Internal\DatabaseRuntime;
use Laratesto\Pipeline\Internal\DatabaseTransactionScope;
use Laratesto\Pipeline\Internal\FailureResult;
use Laratesto\Runtime\LaravelApplicationFactory;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/** Laravel-compatible migrate-once plus per-test transaction strategy. */
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
            $application = $this->factory->current();
            $connections = DatabaseRuntime::connectionNames($application, $this->attribute->connections);

            DatabaseRuntime::restoreInMemoryConnections($application, $connections);

            // An empty selection cannot certify that this process refreshed a schema.
            if ($connections !== [] && (! RefreshDatabaseState::$migrated || ! $this->allSchemasMigrated($connections))) {
                $this->migrateFresh($connections);
                DatabaseRuntime::cacheInMemoryConnections($application, $connections);
                RefreshDatabaseState::$migrated = true;
            }

            $scope = new DatabaseTransactionScope($application, $connections, guardsRefreshState: true);
            $scope->begin();
        } catch (\Throwable $failure) {
            RefreshDatabaseState::$migrated = false;

            return FailureResult::aborted($info, $failure);
        }

        try {
            $result = $next($info);
        } catch (\Throwable $pipelineFailure) {
            $scope->closeQuietly();
            throw $pipelineFailure;
        }

        try {
            $scope->close();
        } catch (\Throwable $failure) {
            return FailureResult::aborted($info, $failure);
        }

        return $result;
    }

    /** @param list<non-empty-string> $connections */
    private function allSchemasMigrated(array $connections): bool
    {
        $application = $this->factory->current();

        foreach ($connections as $connection) {
            if (! DatabaseRuntime::schemaIsMigrated($application, $connection)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<non-empty-string> $connections */
    private function migrateFresh(array $connections): void
    {
        $application = $this->factory->current();
        $kernel = $application->make(ConsoleKernel::class);

        foreach ($connections as $connection) {
            $parameters = [
                '--force' => true,
                '--database' => $connection,
                '--drop-views' => $this->attribute->dropViews,
                '--drop-types' => $this->attribute->dropTypes,
            ];

            if ($this->attribute->seeder !== null) {
                $parameters['--seeder'] = $this->attribute->seeder;
            } else {
                $parameters['--seed'] = $this->attribute->seed;
            }

            $exitCode = $kernel->call('migrate:fresh', $parameters);
            if ($exitCode !== 0) {
                throw new \RuntimeException(sprintf(
                    'migrate:fresh failed for connection %s with exit code %d: %s',
                    $connection,
                    $exitCode,
                    $kernel->output(),
                ));
            }
        }

        $kernel->setArtisan(null);
    }
}
