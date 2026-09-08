<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class AssertEmptyNonArrayTest extends LaravelTestCase
{
    public function test_emptiness_on_non_array(): void
    {
        $value = 'x';

        $this->assertEmpty($value);
        $this->assertNotEmpty($value);
    }
}
