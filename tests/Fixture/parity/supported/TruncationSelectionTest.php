<?php

declare(strict_types=1);

namespace Tests\Feature\Parity;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Ticket 07 supported corpus: truncation selection with literal lists (matrix
 * "DatabaseTruncation selection"): tables and exceptTables as plain literals
 * plus a provably default-only connection selection — the single null entry the
 * trait resolves to the default connection, so the attribute's bare form keeps
 * the same default-only migration, seeding and truncation scope. A selection
 * naming connections would also repoint the target's migrate:fresh and db:seed,
 * so it stays an unconverted residual instead.
 */
final class TruncationSelectionTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [null];

    protected array $tablesToTruncate = ['things'];

    public function test_default_selection_truncates_selected_tables(): void
    {
        $database = $this->app->make('db');

        $this->assertTrue($database->connection('sqlite')->getSchemaBuilder()->hasTable('things'));
        $this->assertSame(0, $database->connection('sqlite')->table('things')->count());

        $this->assertGreaterThan(0, \DB::table('migrations')->count());
    }
}
