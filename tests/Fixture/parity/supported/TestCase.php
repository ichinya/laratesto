<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

/**
 * Ticket 07 supported corpus: a project base class with a SAFE custom bootstrap —
 * service container binding overrides that a Laratesto test can reproduce through
 * `setUpLaravel()`. No lifecycle parameterization, no exotic parent logic.
 *
 * The migration must convert this class once (extends → LaravelTestCase, setUp →
 * setUpLaravel, parent:: dropped) and leave every descendant valid untouched.
 */
abstract class TestCase extends FoundationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind('parity.clock', static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-01-01 00:00:00'));
    }
}
