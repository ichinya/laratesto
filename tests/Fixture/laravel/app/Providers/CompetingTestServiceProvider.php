<?php

declare(strict_types=1);

namespace App\Providers;

use App\Commands\CompetingTestCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers a later `test` command to exercise provider-order conflicts.
 */
final class CompetingTestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([
            CompetingTestCommand::class,
        ]);
    }
}
