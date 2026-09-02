<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Console\Command;

/**
 * Fixture command for the parity migration corpus: a plain Artisan command whose
 * output a migrated `expectsOutput()` chain can verify.
 */
final class ParityPingCommand extends Command
{
    protected $signature = 'parity:ping {--target= : Target to greet}';

    protected $description = 'Parity fixture: pong back the target';

    public function handle(): int
    {
        $this->line('pong ' . $this->option('target'));

        return 0;
    }
}
