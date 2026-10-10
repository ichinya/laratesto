<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Illuminate\Foundation\Application;
use Illuminate\Queue\Queue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Sleep;
use Laratesto\Runtime\LaravelStateCleaner;
use Testo\Assert;
use Testo\Test;

final class LaravelStateCleanerTest
{
    #[Test]
    public function disconnectFailureStillCleansOtherConnectionsAndGlobalState(): void
    {
        $application = new Application();
        $failure = new \RuntimeException('disconnect failure');
        $second = new class { public bool $disconnected = false; public function disconnect(): void { $this->disconnected = true; } };
        $first = new class($failure) { public function __construct(private \Throwable $failure) {} public function disconnect(): void { throw $this->failure; } };
        $application->instance('db', new class($first, $second) {
            public function __construct(private object $first, private object $second) {}
            public function getConnections(): array { return ['first' => $this->first, 'second' => $this->second]; }
        });
        \Illuminate\Support\Facades\Facade::setFacadeApplication($application);
        Queue::createPayloadUsing(static fn() => ['leak' => true]);
        Sleep::fake();
        \Carbon\Carbon::setTestNow('2020-01-01');
        $caught = null;
        try { (new LaravelStateCleaner())->clean($application); } catch (\Throwable $error) { $caught = $error; }
        Assert::same($failure, $caught);
        Assert::true($second->disconnected);
        Assert::false($application->bound('db'));
        Assert::null(\Illuminate\Support\Facades\Facade::getFacadeApplication());
        Assert::false(\Carbon\Carbon::hasTestNow());
        $faked = false;
        Sleep::whenFakingSleep(static function () use (&$faked) { $faked = true; });
        Sleep::usleep(1);
        Assert::false($faked);
    }

    #[Test]
    public function teardownRemovesPayloadHooksBeforeTheNextJob(): void
    {
        Queue::createPayloadUsing(static fn (): array => ['previous_test' => true]);

        try {
            $queue = new class extends SyncQueue {
                public function payload(): array
                {
                    return \json_decode($this->createPayload('Example@handle', 'default'), true);
                }
            };
            Assert::true($queue->payload()['previous_test']);

            (new LaravelStateCleaner())->clean(new Application());

            $payload = $queue->payload();
            Assert::false(\array_key_exists('previous_test', $payload));

            Queue::createPayloadUsing(static fn (): array => ['next_test' => true]);
            Assert::true($queue->payload()['next_test']);
        } finally {
            Queue::createPayloadUsing(null);
            Sleep::fake(false);
        }
    }

    #[Test]
    public function teardownDisablesFakeSleepForTheNextTest(): void
    {
        Sleep::fake();

        try {
            (new LaravelStateCleaner())->clean(new Application());

            $faked = false;
            Sleep::whenFakingSleep(static function () use (&$faked): void {
                $faked = true;
            });
            Sleep::usleep(1);

            Assert::false($faked, 'A completed test must not leave Sleep in fake mode.');
        } finally {
            Sleep::fake(false);
            Queue::createPayloadUsing(null);
        }
    }
}
