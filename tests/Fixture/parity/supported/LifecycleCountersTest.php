<?php

declare(strict_types=1);

namespace Tests\Feature\Parity;

use Testo\Assert;
use Testo\Lifecycle\AfterClass;
use Tests\TestCase;

/**
 * Ticket 07 supported corpus: lifecycle exactly-once probe. After migration and a
 * Testo run, each counter must be exactly 1 — one setUp, one test body, one
 * tearDown per test, with the descendant's parent lifecycle calls rewritten to
 * the Laratesto hooks so the project base behavior keeps executing exactly once
 * per cycle.
 */
final class LifecycleCountersTest extends TestCase
{
    public static int $setUpCalls = 0;

    public static int $tearDownCalls = 0;

    public static int $testBodyCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$setUpCalls++;
    }

    protected function tearDown(): void
    {
        self::$tearDownCalls++;

        parent::tearDown();
    }

    #[AfterClass]
    public static function assertLifecycleExecutedExactlyOnce(): void
    {
        Assert::same(1, self::$setUpCalls);
        Assert::same(1, self::$testBodyCalls);
        Assert::same(1, self::$tearDownCalls);

        // The corpus runs sequentially: once this class is done, every base setup
        // in the process must have received exactly one base teardown.
        Assert::same(TestCase::$baseSetUpCalls, TestCase::$baseTearDownCalls);
    }
    public function test_lifecycle_runs_exactly_once(): void
    {
        self::$testBodyCalls++;

        $this->assertSame(1, self::$setUpCalls);
        $this->assertSame(1, self::$testBodyCalls);

        // Exactly one open base lifecycle cycle (this test's) and the base's own
        // binding is live: the chained parent hook ran the base behavior once.
        $this->assertSame(1, TestCase::$baseSetUpCalls - TestCase::$baseTearDownCalls);
        $this->assertSame('2026-01-01', $this->app->make('parity.clock')->format('Y-m-d'));
    }
}
