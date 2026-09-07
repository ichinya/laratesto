<?php

declare(strict_types=1);

namespace App\Providers;

use App\Commands\ParityPingCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the parity migration corpus fixture command.
 */
final class ParityFixtureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([
            ParityPingCommand::class,
        ]);
    }
}
