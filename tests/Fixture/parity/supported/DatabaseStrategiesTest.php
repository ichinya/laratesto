<?php

declare(strict_types=1);

namespace Tests\Feature\Parity;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 07 supported corpus: common-path database strategies with literal options.
 * RefreshDatabase (plain), RefreshDatabase (seed), DatabaseTransactions; the
 * truncation selection cases live in TruncationSelectionTest.
 */
final class DatabaseStrategiesTest extends TestCase
{
    public function test_refresh_database_migrates_and_isolates(): void
    {
        \DB::table('things')->insert(['name' => 'isolated']);

        $this->assertDatabaseHas('things', ['name' => 'isolated']);
        $this->assertSame(1, \DB::table('things')->count());
    }

    public function test_next_test_starts_clean(): void
    {
        $this->assertSame(0, \DB::table('things')->count());
        $this->assertDatabaseMissing('things', ['name' => 'isolated']);
    }
}

final class WrappedTransactionsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_transaction_wraps_the_test(): void
    {
        \DB::table('things')->insert(['name' => 'rolled-back']);

        $this->assertSame(1, \DB::table('things')->count());
    }
}
