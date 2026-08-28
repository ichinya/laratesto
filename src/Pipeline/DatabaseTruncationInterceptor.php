<?php

declare(strict_types=1);

namespace Laratesto\Pipeline;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\ConnectionInterface;
use Laratesto\Attribute\DatabaseTruncation;
use Laratesto\Pipeline\Internal\FailureResult;
use Laratesto\Runtime\LaravelApplicationFactory;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Truncates the database tables before the test.
 *
 * Mirrors the PHPUnit trait: when the schema is missing (a fresh in-memory database),
 * it runs `migrate:fresh` and stops — nothing to truncate yet. Once the schema exists,
 * every test starts from truncated tables, which avoids re-running migrations the way
 * {@see RefreshDatabaseInterceptor} does.
 *
 * Ordered on the same slot as {@see RefreshDatabaseInterceptor} (both create the
 * schema, so they are not combined) and before {@see DatabaseTransactionsInterceptor},
 * so a test may combine truncation with a wrapping transaction.
 *
 * Truncation failures are returned as aborted test results carrying the original
 * exception instead of being thrown (see {@see FailureResult} for why).
 *
 * @see DatabaseTruncation
 *
 * @api
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_DEFAULT - 50_000)]
final readonly class DatabaseTruncationInterceptor implements TestRunInterceptor
{
    public function __construct(
        private DatabaseTruncation $attribute,
        private LaravelApplicationFactory $factory,
    ) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        try {
            $this->truncateOrMigrate();
        } catch (\Throwable $failure) {
            return FailureResult::aborted($info, $failure);
        }

        return $next($info);
    }

    private function truncateOrMigrate(): void
    {
        $database = $this->factory->current()->make('db');
        $connection = $database->connection($this->attribute->connection);

        $tables = $connection->getSchemaBuilder()->getTableListing();

        if ($tables === []) {
            // First run over a fresh database: the trait migrates instead of truncating.
            $this->migrateFresh();

            return;
        }

        $this->truncateTables($connection, $tables);

        if ($this->attribute->seed || $this->attribute->seeder !== null) {
            $this->seed();
        }
    }

    /**
     * @param list<string> $tables
     */
    private function truncateTables(ConnectionInterface $connection, array $tables): void
    {
        $connection->getSchemaBuilder()->withoutForeignKeyConstraints(
            function () use ($connection, $tables): void {
                foreach ($tables as $table) {
                    if ($this->attribute->tables !== null
                        && ! \in_array($table, $this->attribute->tables, true)) {
                        continue;
                    }

                    $connection->table($table)->truncate();
                }
            },
        );
    }

    private function migrateFresh(): void
    {
        $kernel = $this->factory->current()->make(ConsoleKernel::class);

        $parameters = ['--force' => true];

        $this->attribute->dropViews and $parameters['--drop-views'] = true;
        $this->attribute->dropTypes and $parameters['--drop-types'] = true;
        $this->attribute->seed and $parameters['--seed'] = true;
        $this->attribute->seeder !== null and $parameters['--seeder'] = $this->attribute->seeder;

        $exitCode = $kernel->call('migrate:fresh', $parameters);

        if ($exitCode !== 0) {
            throw new \RuntimeException(\sprintf(
                'migrate:fresh failed with exit code %d: %s',
                $exitCode,
                $kernel->output(),
            ));
        }
    }

    private function seed(): void
    {
        $kernel = $this->factory->current()->make(ConsoleKernel::class);

        $parameters = ['--force' => true];

        $this->attribute->seeder !== null and $parameters['--class'] = $this->attribute->seeder;

        $exitCode = $kernel->call('db:seed', $parameters);

        if ($exitCode !== 0) {
            throw new \RuntimeException(\sprintf(
                'db:seed failed with exit code %d: %s',
                $exitCode,
                $kernel->output(),
            ));
        }
    }
}
