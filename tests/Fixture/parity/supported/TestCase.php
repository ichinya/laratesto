<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

/**
 * Ticket 07 supported corpus: a project base class with a SAFE custom bootstrap —
 * service container binding overrides that a Laratesto test can reproduce through
 * `setUpLaravel()`. No lifecycle parameterization, no exotic parent logic.
 *
 * The migration must convert this class once (extends → LaravelTestCase, setUp →
 * setUpLaravel, parent:: dropped) and leave every descendant valid untouched. The
 * static counters let the corpus prove the base lifecycle runs exactly once per
 * test through the chained parent::setUpLaravel()/parent::tearDownLaravel() calls.
 */
abstract class TestCase extends FoundationTestCase
{
    use RefreshDatabase;

    public static int $baseSetUpCalls = 0;

    public static int $baseTearDownCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$baseSetUpCalls++;

        $this->app->bind('parity.clock', static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-01-01 00:00:00'));
    }

    protected function tearDown(): void
    {
        self::$baseTearDownCalls++;

        parent::tearDown();
    }
}
