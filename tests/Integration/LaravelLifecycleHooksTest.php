<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Laratesto\Testing\LaravelTestCase;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;

final class LaravelLifecycleHooksTest extends LaravelTestCase
{
    private static int $setUps = 0;

    private static int $tearDowns = 0;

    protected function setUpLaravel(): void
    {
        self::$setUps++;
    }

    protected function tearDownLaravel(): void
    {
        self::$tearDowns++;
    }

    public function testFirstHookPair(): void
    {
        Assert::same(self::$setUps, self::$tearDowns + 1);
    }

    public function testPreviousTestWasTornDownBeforeTheNextSetup(): void
    {
        Assert::same(self::$setUps, self::$tearDowns + 1);
        Assert::true(self::$tearDowns >= 1);
    }
}

final class LaravelSkippedSetupTest extends LaravelTestCase
{
    protected function setUpLaravel(): void
    {
        throw new SkipTest('Lifecycle precondition is unavailable.');
    }

    public function testBodyIsSkippedByTheLifecycleHook(): void
    {
        Assert::fail('The test body must not run.');
    }
}
