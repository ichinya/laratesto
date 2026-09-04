<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    protected bool $seed = true;

    protected array $connectionsToTransact = ['mariadb'];
}

namespace Tests\Feature;

final class SeededTest extends \Tests\TestCase
{
    public function test_store(): void
    {
        $this->get('/store');
    }
}

final class OtherTest extends \Tests\TestCase
{
    public function test_other(): void
    {
        $this->get('/other');
    }
}
