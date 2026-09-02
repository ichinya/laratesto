<?php

declare(strict_types=1);

namespace Tests\Feature\Parity;

use Tests\TestCase;

/**
 * Ticket 07 supported corpus: lifecycle exactly-once probe. After migration and a
 * Testo run, each counter must be exactly 1 — one setUp, one test body, one
 * tearDown per test, with `parent::` calls gone.
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

    public function test_lifecycle_runs_exactly_once(): void
    {
        self::$testBodyCalls++;

        $this->assertSame(1, self::$setUpCalls);
        $this->assertSame(1, self::$testBodyCalls);
    }
}
