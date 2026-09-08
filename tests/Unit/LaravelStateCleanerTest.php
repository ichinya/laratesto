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
