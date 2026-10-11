<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Cleanup;

use Testo\Assert;
use Testo\Test;
use Testo\Retry;
use function Laratesto\Tests\Fixture\Compatibility\event;

final class CleanupTest extends \Laratesto\Testing\LaravelTestCase
{
    private bool $failTeardown = false;

    #[Test, Retry(maxAttempts: 2)]
    public function standaloneTeardownError(): void
    {
        event('body');
        $this->failTeardown = true;
        Assert::true(true);
    }
    protected function tearDownLaravel(): void
    {
        if ($this->failTeardown) {
            $this->failTeardown = false;
            throw new \RuntimeException('standalone teardown error');
        }
    }
    #[Test]
    public function afterTeardownError(): void { event('body'); Assert::notNull($this->app()); }

    #[Test, Retry(maxAttempts: 2)]
    public function standaloneDisconnectError(): void
    {
        event('body');
        $this->app()->instance('db', new class {
            public function getConnections(): array { return [new class {
                public function disconnect(): void { throw new \RuntimeException('standalone disconnect error'); }
            }]; }
        });
        Assert::true(true);
    }
    #[Test]
    public function afterDisconnectError(): void { event('body'); Assert::notNull($this->app()); }

    #[\Testo\Lifecycle\AfterClass]
    public static function cleanupWasCompleted(): void
    {
        event('clean', ['facade' => \Illuminate\Support\Facades\Facade::getFacadeApplication() === null]);
    }
}
