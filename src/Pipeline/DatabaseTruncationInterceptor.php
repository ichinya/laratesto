<?php

declare(strict_types=1);

namespace Laratesto\Pipeline;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Laratesto\Attribute\DatabaseTruncation;
use Laratesto\Pipeline\Internal\DatabaseRuntime;
use Laratesto\Pipeline\Internal\FailureResult;
use Laratesto\Runtime\LaravelApplicationFactory;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/** Laravel-compatible first migration followed by selected multi-connection truncation. */
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
        $application = $this->factory->current();
        $connections = DatabaseRuntime::connectionNames($application, $this->attribute->connections);
        DatabaseRuntime::restoreInMemoryConnections($application, $connections);

        // An empty selection cannot certify that this process refreshed a schema.
        if ($connections !== [] && (! RefreshDatabaseState::$migrated || ! $this->allSchemasMigrated($connections))) {
            try {
                $this->migrateFresh($connections);
                DatabaseRuntime::cacheInMemoryConnections($application, $connections);
                RefreshDatabaseState::$migrated = true;
            } catch (\Throwable $failure) {
                RefreshDatabaseState::$migrated = false;
                throw $failure;
            }

            return;
        }

        foreach ($connections as $name) {
            /** @var Connection $connection */
            $connection = $application['db']->connection($name);
            $this->truncateConnection($connection, $name);
        }

        if ($this->attribute->seed || $this->attribute->seeder !== null) {
            $this->seed($connections);
        }
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

    private function truncateConnection(Connection $connection, string $name): void
    {
        $schema = $connection->getSchemaBuilder();
        $tables = $schema->getTables($schema->getCurrentSchemaListing());
        $included = $this->selectionFor($this->attribute->tables, $name);
        $excluded = $included === null
            ? $this->selectionFor($this->attribute->exceptTables, $name) ?? []
            : [];
        $excluded[] = DatabaseRuntime::migrationsTable($this->factory->current(), $name);

        $schema->withoutForeignKeyConstraints(function () use ($connection, $tables, $included, $excluded): void {
            $dispatcher = $connection->getEventDispatcher();
            $connection->unsetEventDispatcher();

            try {
                foreach ($tables as $table) {
                    if (! is_array($table)) {
                        continue;
                    }

                    if ($included !== null && ! $this->tableExistsIn($table, $included)) {
                        continue;
                    }

                    if ($included === null && $this->tableExistsIn($table, $excluded)) {
                        continue;
                    }

                    $qualified = (string) ($table['schema_qualified_name'] ?? $table['name'] ?? '');
                    if ($qualified === '') {
                        continue;
                    }

                    $connection->withoutTablePrefix(function (Connection $connection) use ($qualified): void {
                        $query = $connection->table($qualified);
                        if ($query->exists()) {
                            $query->truncate();
                        }
                    });
                }
            } finally {
                $connection->setEventDispatcher($dispatcher);
            }
        });
    }

    /**
     * @param list<non-empty-string>|array<string, list<non-empty-string>>|null $selection
     * @return list<non-empty-string>|null
     */
    private function selectionFor(?array $selection, string $connection): ?array
    {
        if ($selection === null) {
            return null;
        }

        if (! array_is_list($selection)) {
            if (! array_key_exists($connection, $selection)) {
                return [];
            }

            $selected = $selection[$connection];

            return is_array($selected) && $selected !== [] ? array_values($selected) : null;
        }

        return $selection === [] ? null : array_values($selection);
    }

    /** @param array<string, mixed> $table @param list<non-empty-string> $selection */
    private function tableExistsIn(array $table, array $selection): bool
    {
        $name = (string) ($table['name'] ?? '');
        $qualified = (string) ($table['schema_qualified_name'] ?? $name);

        return in_array($name, $selection, true) || in_array($qualified, $selection, true);
    }

    /** @param list<non-empty-string> $connections */
    private function migrateFresh(array $connections): void
    {
        $kernel = $this->factory->current()->make(ConsoleKernel::class);

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

    /** @param list<non-empty-string> $connections */
    private function seed(array $connections): void
    {
        $kernel = $this->factory->current()->make(ConsoleKernel::class);

        foreach ($connections as $connection) {
            $parameters = ['--force' => true, '--database' => $connection];
            if ($this->attribute->seeder !== null) {
                $parameters['--class'] = $this->attribute->seeder;
            }

            $exitCode = $kernel->call('db:seed', $parameters);
            if ($exitCode !== 0) {
                throw new \RuntimeException(sprintf(
                    'db:seed failed for connection %s with exit code %d: %s',
                    $connection,
                    $exitCode,
                    $kernel->output(),
                ));
            }
        }
    }
}
