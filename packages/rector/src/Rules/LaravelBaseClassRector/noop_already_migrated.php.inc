<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class AlreadyMigratedTest extends LaravelTestCase
{
    protected function setUpLaravel(): void
    {
    }

    #[\Testo\Test]
    public function test_already_migrated(): void
    {
        $this->get('/users')->assertStatus(200);
    }
}
