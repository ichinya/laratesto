<?php

declare(strict_types=1);

namespace Laratesto\Pipeline\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/** @internal Shared Laravel-compatible connection and in-memory state handling. */
final class DatabaseRuntime
{
    /**
     * Resolves a database attribute's connection selection: null/unset falls
     * back to the default connection, an explicitly empty list selects no
     * connections at all (the Laravel traits iterate the selection and touch
     * nothing), and null/empty-string entries select the default connection.
     *
     * @param list<string|null>|null $configured
     * @return list<non-empty-string>
     */
    public static function connectionNames(Application $application, ?array $configured): array
    {
        $default = (string) $application['config']->get('database.default');

        if ($configured === null) {
            return [$default];
        }

        $names = [];
        foreach ($configured as $name) {
            $resolved = $name === null || $name === '' ? $default : (string) $name;
            if ($resolved !== '' && ! in_array($resolved, $names, true)) {
                $names[] = $resolved;
            }
        }

        return $names;
    }

    /** @param list<non-empty-string> $names */
    public static function restoreInMemoryConnections(Application $application, array $names): void
    {
        foreach ($names as $name) {
            if (! self::isInMemory($application, $name) || ! isset(RefreshDatabaseState::$inMemoryConnections[$name])) {
                continue;
            }

            $connection = $application['db']->connection($name);
            $cached = RefreshDatabaseState::$inMemoryConnections[$name];

            // Re-attaching the same cached PDO resets the transaction depth
            // counter (setPdo does that) and corrupts an already-open scope.
            if ($connection->getPdo() === $cached) {
                continue;
            }

            $connection->setPdo($cached);
        }
    }

    /**
     * Roll back a transaction stranded on a cached in-memory PDO by a
     * mid-test disconnect, so the cached database stays reusable.
     */
    public static function rollbackCachedTransaction(Application $application, string $name): void
    {
        if (! self::isInMemory($application, $name)) {
            return;
        }

        $cached = RefreshDatabaseState::$inMemoryConnections[$name] ?? null;

        if ($cached !== null && $cached->inTransaction()) {
            $cached->rollBack();
        }
    }

    /** @param list<non-empty-string> $names */
    public static function cacheInMemoryConnections(Application $application, array $names): void
    {
        foreach ($names as $name) {
            if (! self::isInMemory($application, $name)) {
                continue;
            }

            $pdo = $application['db']->connection($name)->getPdo();

            // A disconnected (null) PDO must not overwrite a healthy cached one:
            // restoring it later is what keeps the in-memory schema alive.
            if ($pdo !== null) {
                RefreshDatabaseState::$inMemoryConnections[$name] = $pdo;
            }
        }
    }

    public static function isInMemory(Application $application, string $name): bool
    {
        return $application['config']->get("database.connections.{$name}.database") === ':memory:';
    }

    public static function migrationsTable(Application $application, string $connection): string
    {
        return $application['db']->connection($connection)->getTablePrefix()
            . self::configuredMigrationsTable($application);
    }

    public static function schemaIsMigrated(Application $application, string $connection): bool
    {
        return $application['db']->connection($connection)
            ->getSchemaBuilder()
            ->hasTable(self::configuredMigrationsTable($application));
    }

    private static function configuredMigrationsTable(Application $application): string
    {
        $configured = $application['config']->get('database.migrations', 'migrations');

        return (string) (is_array($configured) ? ($configured['table'] ?? 'migrations') : $configured);
    }
}
