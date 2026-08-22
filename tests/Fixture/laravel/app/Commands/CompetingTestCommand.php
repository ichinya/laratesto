<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Console\Command;

/**
 * Fixture command that mimics Collision registering `test` after Laratesto.
 */
final class CompetingTestCommand extends Command
{
    protected $signature = 'test';

    protected $description = 'Fixture command that must be superseded by Laratesto';

    public function handle(): int
    {
        $this->error('The competing test command was selected.');

        return 91;
    }
}
