<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class PlainPhpUnitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_plain(): void
    {
        self::assertTrue(true);
    }
}
