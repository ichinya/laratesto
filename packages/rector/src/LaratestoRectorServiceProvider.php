<?php

declare(strict_types=1);

namespace Laratesto\Rector;

use Illuminate\Support\ServiceProvider;
use Laratesto\Rector\Console\MigrateRectorCommand;

/**
 * Registers the Artisan entry point of the Rector-based migration.
 *
 * @api
 */
final class LaratestoRectorServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateRectorCommand::class,
            ]);
        }
    }
}
