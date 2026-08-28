<?php

declare(strict_types=1);

namespace Laratesto\Pipeline\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/** @internal Shared Laravel-compatible connection and in-memory state handling. */
final class DatabaseRuntime
{
    /**
     * @param list<string|null>|null $configured
     * @return list<non-empty-string>
     */
    public static function connectionNames(Application $application, ?array $configured): array
    {
        $default = (string) $application['config']->get('database.default');
        $configured ??= [$default];

        $names = [];
        foreach ($configured as $name) {
            $resolved = $name === null || $name === '' ? $default : (string) $name;
            if ($resolved !== '' && ! in_array($resolved, $names, true)) {
                $names[] = $resolved;
            }
        }

        return $names === [] ? [$default] : $names;
    }

    /** @param list<non-empty-string> $names */
    public static function restoreInMemoryConnections(Application $application, array $names): void
    {
        foreach ($names as $name) {
            if (! self::isInMemory($application, $name) || ! isset(RefreshDatabaseState::$inMemoryConnections[$name])) {
                continue;
            }

            $application['db']->connection($name)->setPdo(RefreshDatabaseState::$inMemoryConnections[$name]);
        }
    }

    /** @param list<non-empty-string> $names */
    public static function cacheInMemoryConnections(Application $application, array $names): void
    {
        foreach ($names as $name) {
            if (self::isInMemory($application, $name)) {
                RefreshDatabaseState::$inMemoryConnections[$name] = $application['db']->connection($name)->getPdo();
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
