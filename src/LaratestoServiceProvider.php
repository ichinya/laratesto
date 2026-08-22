<?php

declare(strict_types=1);

namespace Laratesto;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\ServiceProvider;
use Laratesto\Console\Commands\MigratePhpUnitCommand;
use Laratesto\Console\Commands\RunTestsCommand;
use Laratesto\Console\SymfonyTestProcessRunner;
use Laratesto\Console\TestProcessRunner;

/**
 * Laravel package integration for development-time Laratesto commands.
 */
final class LaratestoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TestProcessRunner::class, SymfonyTestProcessRunner::class);
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            MigratePhpUnitCommand::class,
        ]);

        // Collision may register its own `test` command after package discovery.
        // App-level booted callbacks run after every provider, so this resolver is
        // appended last and consistently makes Laratesto the command owner.
        $this->app->booted(static function (): void {
            Artisan::starting(static function (Artisan $artisan): void {
                $artisan->resolveCommands([
                    RunTestsCommand::class,
                ]);
            });
        });
    }
}
