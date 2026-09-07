<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ValueTest extends TestCase
{
    public function test_value(): void
    {
        self::assertSame(1, 1);
    }
}
