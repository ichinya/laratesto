<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class ParentSurfaceTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_parent_helpers(): void
    {
        parent::artisan('cache:clear', []);
        parent::assertDatabaseHas('users', ['email' => 'a@b.c']);
        parent::get('/users')->assertOk();
    }
}

