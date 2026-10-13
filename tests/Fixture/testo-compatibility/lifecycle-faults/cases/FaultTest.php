<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\LifecycleFaults;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Queue\Queue;
use Illuminate\Queue\SyncQueue;
use Laratesto\Attribute\DatabaseTransactions;
use Testo\Assert;
use Testo\Test;
use function Laratesto\Tests\Fixture\Compatibility\event;

final class FaultTest extends \Laratesto\Testing\LaravelTestCase
{
    private int $boots = 0;
    private bool $polluted = false;
    private bool $faultBody = false;

    public function createApplication(): Application
    {
        $scenario = getenv('LARATESTO_LIFECYCLE_FAULT');
        if ($this->boots++ > 0) {
            $cached = RefreshDatabaseState::$inMemoryConnections['sqlite'] ?? null;
            event('between tests', [
                'facade_cleared' => Facade::getFacadeApplication() === null,
                'container_cleared' => !\Illuminate\Container\Container::getInstance()->bound('fault.leak'),
                'cached_transaction' => $cached?->inTransaction() ?? false,
                'cached_available' => $cached !== null,
                'migrated' => RefreshDatabaseState::$migrated,
            ]);
        } elseif ($scenario === 'no-application') {
            $this->polluted = true;
            Queue::createPayloadUsing(static fn() => ['fault_hook' => true]);
            throw new \RuntimeException('primary no-application bootstrap fault');
        }

        $application = require dirname(__DIR__, 3).'/laravel/bootstrap/app.php';
        $application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $application['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
        $application->instance('fault.owner', bin2hex(random_bytes(8)));

        if ($this->boots === 1 && $scenario === 'factory') {
            $this->pollute($application);
            throw new \RuntimeException('primary factory bootstrap fault');
        }
        if ($this->boots === 1 && in_array($scenario, ['rollback', 'unrecoverable-rollback', 'disconnect'], true)) {
            $application['db']->extend('sqlite', static function ($config) use ($application, $scenario) {
                $pdo = $scenario === 'unrecoverable-rollback'
                    ? new class('sqlite::memory:') extends \PDO {
                        public function rollBack(): bool { throw new \RuntimeException('secondary PDO rollback fault'); }
                    }
                    : new \PDO('sqlite::memory:');
                $connection = new class($pdo, ':memory:', '', $config, $scenario) extends \Illuminate\Database\SQLiteConnection {
                    public function __construct($pdo, $database, $prefix, $config, private string $scenario)
                    {
                        parent::__construct($pdo, $database, $prefix, $config);
                    }
                    public function rollBack($toLevel = null): void
                    {
                        if ($this->scenario !== 'disconnect') {
                            throw new \RuntimeException('secondary rollback fault');
                        }
                        parent::rollBack($toLevel);
                    }
                    public function disconnect(): void
                    {
                        if ($this->scenario === 'disconnect') {
                            throw new \RuntimeException('secondary disconnect fault');
                        }
                        parent::disconnect();
                    }
                };
                $connection->setEventDispatcher($application['events']);
                return $connection;
            });
        }
        // Schema preparation is outside the test transaction so it survives rollback.
        $application['db']->connection('sqlite')->statement('CREATE TABLE IF NOT EXISTS fault_rows (id INTEGER)');
        if ($this->boots === 1 && in_array($scenario, ['rollback', 'unrecoverable-rollback'], true)) {
            // RefreshDatabase also keeps its PDO before cleanup starts. Exercise
            // recovery with an existing cache entry, not only an empty cache.
            RefreshDatabaseState::$inMemoryConnections['sqlite'] = $application['db']->connection('sqlite')->getPdo();
            RefreshDatabaseState::$migrated = true;
        }
        return $application;
    }

    protected function setUpLaravel(): void
    {
        if (!$this->polluted) {
            $this->pollute($this->app());
            $this->app()['db']->connection('sqlite')->table('fault_rows')->insert(['id' => 1]);
            if (getenv('LARATESTO_LIFECYCLE_FAULT') === 'setup') {
                throw new \RuntimeException('primary setup fault');
            }
        }
    }

    private function pollute(Application $application): void
    {
        $this->polluted = true;
        $application->instance('fault.leak', true);
        Facade::setFacadeApplication($application);
        Cache::put('fault.leak', true);
        Auth::guard()->setUser(new \App\Models\TestUser(19, 'Lifecycle fault'));
        \Carbon\Carbon::setTestNow('2020-01-01');
        Queue::createPayloadUsing(static fn() => ['fault_hook' => true]);
        event('polluted', ['owner' => $application->make('fault.owner')]);
    }

    #[Test, DatabaseTransactions]
    public function fault(): void
    {
        $this->faultBody = true;
        if (in_array(getenv('LARATESTO_LIFECYCLE_FAULT'), ['body-teardown', 'rollback', 'unrecoverable-rollback', 'disconnect'], true)) {
            Assert::true(false, 'primary body fault');
        }
        Assert::true(true);
    }

    protected function tearDownLaravel(): void
    {
        if ($this->faultBody && in_array(getenv('LARATESTO_LIFECYCLE_FAULT'), ['teardown', 'body-teardown'], true)) {
            $this->faultBody = false;
            throw new \RuntimeException('secondary teardown fault');
        }
    }

    #[Test, DatabaseTransactions]
    public function following(): void
    {
        $this->faultBody = false;
        Assert::false($this->app()->bound('fault.leak'));
        Assert::same($this->app(), Facade::getFacadeApplication());
        Assert::same($this->app(), \Illuminate\Container\Container::getInstance());
        Assert::true(Auth::guest());
        Assert::null(Cache::get('fault.leak'));
        Assert::false(\Carbon\Carbon::hasTestNow());
        $queue = new class extends SyncQueue {
            public function payload(): array { return json_decode($this->createPayload('Example@handle', 'default'), true); }
        };
        Assert::false(isset($queue->payload()['fault_hook']));
        Assert::same(0, $this->app()['db']->connection('sqlite')->table('fault_rows')->count());
        Assert::same(1, $this->app()['db']->connection('sqlite')->transactionLevel());
        event('clean', ['owner' => $this->app()->make('fault.owner')]);
    }
}
