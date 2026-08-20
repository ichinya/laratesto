<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Laratesto\Attribute\RefreshDatabase;
use Laratesto\Testing\LaravelTestCase;

final class DatabaseAssertsTest extends LaravelTestCase
{
    #[RefreshDatabase]
    public function testDatabaseCount(): void
    {
        $this->assertDatabaseCount('things', 0);

        DB::table('things')->insert(['name' => 'alpha']);
        DB::table('things')->insert(['name' => 'beta']);

        $this->assertDatabaseCount('things', 2);
    }

    #[RefreshDatabase]
    public function testDatabaseHasAndMissing(): void
    {
        DB::table('things')->insert(['name' => 'alpha']);

        $this->assertDatabaseHas('things', ['name' => 'alpha']);
        $this->assertDatabaseMissing('things', ['name' => 'beta']);
    }
}
