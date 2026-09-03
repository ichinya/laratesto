<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Illuminate\Contracts\Foundation\Application;
use Laratesto\Config\LaravelConfig;
use Laratesto\Pipeline\Internal\DatabaseRuntime;
use Laratesto\Runtime\LaravelApplicationFactory;
use Testo\Assert;
use Testo\Test;

/**
 * The database attributes' connection selection contract: null/unset falls
 * back to the default connection, an explicitly empty list selects no
 * connections at all (Laravel parity), and null/empty-string entries resolve
 * to the default connection.
 */
final class DatabaseRuntimeConnectionNamesTest
{
    #[Test]
    public function anUnsetSelectionFallsBackToTheDefaultConnection(): void
    {
        Assert::same(self::connectionNames(null), ['sqlite']);
    }

    #[Test]
    public function anExplicitlyEmptySelectionSelectsNoConnections(): void
    {
        Assert::same(self::connectionNames([]), []);
    }

    #[Test]
    public function nullEntriesSelectTheDefaultConnection(): void
    {
        Assert::same(self::connectionNames(['secondary', null]), ['secondary', 'sqlite']);
    }

    #[Test]
    public function emptyStringEntriesSelectTheDefaultConnection(): void
    {
        Assert::same(self::connectionNames(['', 'secondary']), ['sqlite', 'secondary']);
    }

    #[Test]
    public function duplicateEntriesAreDeduplicatedKeepingTheFirstOccurrence(): void
    {
        Assert::same(self::connectionNames(['secondary', 'sqlite', 'secondary']), ['secondary', 'sqlite']);
    }

    /** @param list<string|null>|null $configured */
    private static function connectionNames(?array $configured): array
    {
        return DatabaseRuntime::connectionNames(self::application(), $configured);
    }

    private static function application(): Application
    {
        static $application = null;

        if ($application === null) {
            $factory = new LaravelApplicationFactory(new LaravelConfig(
                basePath: \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'Fixture' . \DIRECTORY_SEPARATOR . 'laravel',
            ));
            $factory->boot();
            $application = $factory->current();
        }

        return $application;
    }
}
