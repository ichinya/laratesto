<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Internal\Path;
use Laratesto\Attribute\RefreshDatabase;
use Laratesto\Attribute\DatabaseTruncation;
use Laratesto\Config\LaravelConfig;
use Laratesto\Pipeline\RefreshDatabaseInterceptor;
use Laratesto\Pipeline\DatabaseTruncationInterceptor;
use Laratesto\Runtime\LaravelApplicationFactory;
use Testo\Assert;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\Identity\SuiteIdentity;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Definition\CaseDefinition;
use Testo\Core\Definition\TestDefinition;
use Testo\Core\Value\Status;
use Testo\Test;

final class DatabaseEmptyScopeTest
{
    #[Test]
    public function emptyScopeDoesNotSuppressTheFirstRealRefreshOfAnExistingSchema(): void
    {
        self::withMigrationState(false, static function (): void {
            [$factory, $kernel] = self::factory();
            $info = self::info();
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            $empty = new RefreshDatabaseInterceptor(new RefreshDatabase(connections: []), $factory);
            Assert::same($empty->runTest($info, $next)->status, Status::Passed);
            Assert::same($kernel->calls, []);
            Assert::false(RefreshDatabaseState::$migrated);

            $normal = new RefreshDatabaseInterceptor(new RefreshDatabase(), $factory);
            Assert::same($normal->runTest($info, $next)->status, Status::Passed);
            Assert::same(count($kernel->calls), 1);
            Assert::same($kernel->calls[0][0], 'migrate:fresh');
            Assert::same($kernel->calls[0][1]['--database'], 'main');
            Assert::true(RefreshDatabaseState::$migrated);

            // Once a real scope has refreshed this schema, later scopes reuse it.
            Assert::same($normal->runTest($info, $next)->status, Status::Passed);
            Assert::same(count($kernel->calls), 1);
        });
    }

    #[Test]
    public function emptyTruncationDoesNotSuppressTheFirstRealRefreshOfAnExistingSchema(): void
    {
        self::withMigrationState(false, static function (): void {
            [$factory, $kernel] = self::factory();
            $info = self::info();
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            $empty = new DatabaseTruncationInterceptor(new DatabaseTruncation(connections: []), $factory);
            Assert::same($empty->runTest($info, $next)->status, Status::Passed);
            Assert::same($kernel->calls, []);
            Assert::false(RefreshDatabaseState::$migrated);

            $normal = new RefreshDatabaseInterceptor(new RefreshDatabase(), $factory);
            Assert::same($normal->runTest($info, $next)->status, Status::Passed);
            Assert::same(count($kernel->calls), 1);
            Assert::same($kernel->calls[0][0], 'migrate:fresh');
            Assert::same($kernel->calls[0][1]['--database'], 'main');
            Assert::true(RefreshDatabaseState::$migrated);

            // Once a real scope has refreshed this schema, later scopes reuse it.
            Assert::same($normal->runTest($info, $next)->status, Status::Passed);
            Assert::same(count($kernel->calls), 1);
        });
    }

    #[Test]
    public function emptyScopePreservesAnAlreadyMigratedSchema(): void
    {
        self::withMigrationState(true, static function (): void {
            [$factory, $kernel] = self::factory();
            $empty = new RefreshDatabaseInterceptor(new RefreshDatabase(connections: []), $factory);
            $result = $empty->runTest(self::info(), static function (TestInfo $info): TestResult {
                Assert::true(RefreshDatabaseState::$migrated);

                return new TestResult(info: $info, status: Status::Passed);
            });

            Assert::same($result->status, Status::Passed);
            Assert::same($kernel->calls, []);
            Assert::true(RefreshDatabaseState::$migrated);

            $truncation = new DatabaseTruncationInterceptor(new DatabaseTruncation(connections: []), $factory);
            Assert::same($truncation->runTest(self::info(), static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed))->status, Status::Passed);
            Assert::same($kernel->calls, []);
            Assert::true(RefreshDatabaseState::$migrated);
        });
    }

    private static function withMigrationState(bool $migrated, callable $test): void
    {
        $previousContainer = Container::getInstance();
        $previousMigrated = RefreshDatabaseState::$migrated;
        $previousConnections = RefreshDatabaseState::$inMemoryConnections;
        RefreshDatabaseState::$migrated = $migrated;
        RefreshDatabaseState::$inMemoryConnections = [];

        try {
            $test();
        } finally {
            RefreshDatabaseState::$migrated = $previousMigrated;
            RefreshDatabaseState::$inMemoryConnections = $previousConnections;
            Container::setInstance($previousContainer);
        }
    }

    /** @return array{LaravelApplicationFactory, object} */
    private static function factory(): array
    {
        $application = new Application();
        $application->instance('config', new Repository([
            'database' => [
                'default' => 'main',
                'migrations' => 'migrations',
                'connections' => ['main' => ['database' => ':memory:']],
            ],
        ]));
        $connection = new SQLiteConnection(new \PDO('sqlite::memory:'), '', '', ['name' => 'main']);
        $connection->setEventDispatcher(new Dispatcher());
        // A table left by a previous process must not substitute for this run's first refresh.
        $connection->statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
        $connection->statement("INSERT INTO migrations VALUES (1, 'previous_process_migration', 1)");
        $application->instance('db', new class($connection) {
            public function __construct(private SQLiteConnection $connection) {}

            public function connection(?string $name = null): SQLiteConnection
            {
                return $this->connection;
            }
        });
        $kernel = new class {
            public array $calls = [];

            public function call(string $command, array $parameters = []): int
            {
                $this->calls[] = [$command, $parameters];

                return 0;
            }

            public function setArtisan(mixed $artisan): void {}
        };
        $application->instance(Kernel::class, $kernel);
        $factory = new LaravelApplicationFactory(new LaravelConfig(__DIR__));
        (new \ReflectionProperty($factory, 'application'))->setValue($factory, $application);

        return [$factory, $kernel];
    }

    private static function info(): TestInfo
    {
        return new TestInfo(
            name: 'refresh-empty-scope',
            caseInfo: new CaseInfo(
                definition: new CaseDefinition(name: 'RefreshEmptyScope', type: 'test', file: Path::create(__FILE__)),
                suiteIdentity: new SuiteIdentity('Unit'),
            ),
            testDefinition: new TestDefinition(reflection: new \ReflectionFunction(static fn(): bool => true)),
        );
    }
}
