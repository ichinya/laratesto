<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

#[\Laratesto\Attribute\RefreshDatabase]
final class AlreadyMigratedTest extends LaravelTestCase
{
    public function test_users(): void
    {
        $this->get('/users')->assertStatus(200);
    }
}
