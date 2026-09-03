<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class PlainPhpUnitTest extends TestCase
{
    /**
     * Plain PHPUnit discovery is upstream Testo's business, not this rule's.
     *
     * @test
     */
    public function it_stays_unhandled_here(): void
    {
        self::assertTrue(true);
    }
}
