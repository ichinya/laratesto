<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class CallCommonSignatureSupportedTest extends TestCase
{
    public function test_example(): void
    {
        $this->call("GET", "/")->assertOk();
        $this->call("POST", "/", [], [], [], [], null)->assertOk();
    }
}
