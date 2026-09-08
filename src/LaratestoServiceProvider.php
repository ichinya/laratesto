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
        $this->mergeConfigFrom(__DIR__ . '/../config/laratesto.php', 'laratesto');
        $this->app->singleton(TestProcessRunner::class, SymfonyTestProcessRunner::class);
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            MigratePhpUnitCommand::class,
            RunTestsCommand::class,
        ]);

        $this->publishes([__DIR__ . '/../config/laratesto.php' => $this->app->configPath('laratesto.php')], 'laratesto-config');

        if (!$this->app['config']->get('laratesto.replace_test_command', false)) {
            return;
        }

        // Collision may register its own `test` command after package discovery.
        // App-level booted callbacks run after every provider, so this resolver is
        // appended last and consistently makes Laratesto the command owner.
        $this->app->booted(static function (): void {
            Artisan::starting(static function (Artisan $artisan): void {
                $command = $artisan->getLaravel()->make(RunTestsCommand::class);
                $command->setName('test');
                $command->setAliases(['laratesto:test']);
                $artisan->add($command);
            });
        });
    }
}
