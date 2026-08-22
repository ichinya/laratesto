<?php

declare(strict_types=1);

namespace Laratesto\Pipeline;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Laratesto\Attribute\DatabaseMigrations;
use Laratesto\Pipeline\Internal\FailureResult;
use Laratesto\Runtime\LaravelApplicationFactory;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Mirrors Laravel's DatabaseMigrations PHPUnit lifecycle.
 *
 * @api
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_DEFAULT - 50_000)]
final readonly class DatabaseMigrationsInterceptor implements TestRunInterceptor
{
    public function __construct(
        private DatabaseMigrations $attribute,
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

        try {
            $result = $next($info);
        } catch (\Throwable $pipelineFailure) {
            $this->rollbackQuietly();
            throw $pipelineFailure;
        }

        try {
            $this->rollback();
        } catch (\Throwable $failure) {
            return FailureResult::aborted($info, $failure);
        }

        return $result;
    }

    private function migrateFresh(): void
    {
        $parameters = [
            '--force' => true,
            '--drop-views' => $this->attribute->dropViews,
            '--drop-types' => $this->attribute->dropTypes,
        ];

        if ($this->attribute->seeder !== null) {
            $parameters['--seeder'] = $this->attribute->seeder;
        } else {
            $parameters['--seed'] = $this->attribute->seed;
        }

        $this->callArtisan('migrate:fresh', $parameters);
    }

    private function rollback(): void
    {
        $this->callArtisan('migrate:rollback', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
    }

    private function rollbackQuietly(): void
    {
        try {
            $this->rollback();
        } catch (\Throwable) {
            // The pipeline failure remains authoritative.
        }
    }

    /** @param array<string, bool|string> $parameters */
    private function callArtisan(string $command, array $parameters): void
    {
        $kernel = $this->factory->current()->make(ConsoleKernel::class);
        $exitCode = $kernel->call($command, $parameters);

        if ($exitCode !== 0) {
            throw new \RuntimeException(\sprintf(
                '%s failed with exit code %d: %s',
                $command,
                $exitCode,
                $kernel->output(),
            ));
        }
    }
}
