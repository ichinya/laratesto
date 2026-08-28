<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\TestCase;

final class HelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_helper(): void
    {
        self::assertTrue(true);
    }
}
