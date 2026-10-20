<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\LogChannels;

use Illuminate\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laratesto\Testing\InteractsWithLaravel;
use Laratesto\Testing\LaravelTestCase;
use Testo\Assert;
use Testo\Test;

trait BuildsLogApplication
{
    private ?\Illuminate\Contracts\Events\Dispatcher $previousEvents = null;

    public function createApplication(): Application
    {
        $this->previousEvents?->dispatch(new MessageLogged('info', 'stale application message'));
        $app = require dirname(__DIR__, 3).'/laravel/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $this->previousEvents = $app['events'];
        $app['events']->dispatch(new MessageLogged('info', 'bootstrap message outside capture'));
        $app['db']->extend('sqlite', static fn($config, $name) => new class(new \PDO('sqlite::memory:'), ':memory:', '', ['name' => $name] + $config) extends \Illuminate\Database\SQLiteConnection {
            public function disconnect(): void
            {
                $this->getEventDispatcher()?->dispatch(new MessageLogged('info', 'connection cleanup'));
                parent::disconnect();
            }
        });
        return $app;
    }
}

#[Test]
final class BaseCase extends LaravelTestCase
{
    use BuildsLogApplication;

    private int $retries = 0;
    private int $repeats = 0;

    protected function setUpLaravel(): void
    {
        Log::info('setup message');
    }

    protected function tearDownLaravel(): void
    {
        Log::info('teardown message');
    }

    public function failure(): void
    {
        DB::select('select ? as value /* body */', ['private-binding']);
        Log::warning('body message', ['token' => 'private-context']);
        Assert::true(false, 'primary body failure');
    }

    public function following(): void
    {
        DB::connection('secondary')->select('select 1 /* following */');
        Log::info('following message');
        Assert::true(true);
    }

    #[\Testo\Retry(maxAttempts: 2)]
    public function retry(): void
    {
        Log::notice('retry execution '.++$this->retries);
        Assert::true($this->retries > 1, 'retry first attempt');
    }

    #[\Testo\Repeat(times: 2)]
    public function repeat(): void
    {
        Log::notice('repeat execution '.++$this->repeats);
        Assert::true(true);
    }

    #[\Laratesto\Attribute\RefreshDatabase]
    public function database(): void
    {
        Assert::true(DB::getSchemaBuilder()->hasTable('things'));
    }

    #[\Testo\Fiber\RunInFiber]
    public function fiber(): void
    {
        Log::info('fiber before suspension');
        \Fiber::suspend();
        DB::select('select 1 /* fiber */');
        Log::info('fiber after suspension');
        Assert::notNull(\Fiber::getCurrent());
    }
}

#[Test]
final class TraitCase
{
    use InteractsWithLaravel;

    public function plainTrait(): void
    {
        $this->app()['log']->info('trait message');
        Assert::true(true);
    }
}

#[Test]
final class FaultCase extends LaravelTestCase
{
    use BuildsLogApplication;

    private bool $first = true;
    private bool $failing = false;

    protected function setUpLaravel(): void
    {
        $this->failing = $this->first;
        $this->first = false;
        Log::info('fault setup');
        if ($this->failing && getenv('LARATESTO_LOG_FAULT') === 'setup') {
            throw new \RuntimeException('primary setup failure');
        }
    }

    public function fault(): void
    {
        Log::warning('unserializable context', ['value' => new class implements \JsonSerializable {
            public function jsonSerialize(): mixed { throw new \RuntimeException('context serialization failed'); }
        }]);
        Assert::true(false, 'primary fault body failure');
    }

    protected function tearDownLaravel(): void
    {
        Log::info('fault teardown');
        if ($this->failing && getenv('LARATESTO_LOG_FAULT') !== 'setup') {
            throw new \RuntimeException('secondary teardown failure');
        }
    }

    public function clean(): void
    {
        Log::info('clean after fault');
        Assert::true(true);
    }
}
