<?php

declare(strict_types=1);

namespace Tests\Feature\Parity;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Ticket 07 supported corpus: truncation selection over two connections with
 * literal lists (matrix "DatabaseTruncation selection"): connections, tables,
 * exceptTables as plain literals, migrations table always kept.
 */
final class TruncationSelectionTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['sqlite', 'secondary'];

    protected array $tablesToTruncate = ['things'];

    public function test_selected_connections_truncate_selected_tables(): void
    {
        $database = $this->app->make('db');

        foreach (['sqlite', 'secondary'] as $name) {
            $this->assertTrue($database->connection($name)->getSchemaBuilder()->hasTable('things'));
            $this->assertSame(0, $database->connection($name)->table('things')->count());
        }

        $this->assertGreaterThan(0, \DB::table('migrations')->count());
    }
}
