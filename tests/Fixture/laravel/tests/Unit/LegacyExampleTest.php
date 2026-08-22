<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LegacyExampleTest extends TestCase
{
    public function testValueIsPreserved(): void
    {
        self::assertSame('expected', 'expected');
    }
}
