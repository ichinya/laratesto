<?php

declare(strict_types=1);

namespace Tests\Unit\Issue10;

use PHPUnit\Framework\TestCase;

final class StrictStringParityTest extends TestCase
{
    public function testStrictCallerRejectsIntegers(): void
    {
        $this->expectException(\TypeError::class);
        $this->assertStringContainsString('1', 123);
    }

    public function testStrictCallerRejectsStringableObjects(): void
    {
        $this->expectException(\TypeError::class);
        $this->assertStringNotContainsString('9', new \Illuminate\Support\HtmlString('123'));
    }
}
