<?php

declare(strict_types=1);

namespace Testo\Bridge\Laravel\Runtime;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Testo\Bridge\Laravel\Config\LaravelConfig;

/**
 * Boots a Laravel application for every test.
 *
 * The application is created the same way the official Laravel entry points do it:
 * `bootstrap/app.php` is required and the Console kernel is bootstrapped. A fresh
 * application instance is created per test and kept available until
 * {@see flush()} so that database interceptors can resolve the current instance.
 *
 * @api
 */
final class LaravelApplicationFactory
{
    private ?Application $application = null;

    public function __construct(
        private readonly LaravelConfig $config,
    ) {}

    /**
     * Boot a fresh Laravel application.
     *
     * @throws \RuntimeException If the bootstrap file does not return an application instance.
     */
    public function boot(): Application
    {
        $this->flush();

        $environment = $this->config->environment;

        // The environment must be known before the framework bootstrappers run,
        // otherwise Laravel loads ".env" instead of ".env.{$environment}".
        // Dotenv repository is immutable, so repeated boots keep the same values.
        \putenv("APP_ENV={$environment}");
        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;

        $application = require $this->config->bootstrapFile();

        if (!$application instanceof Application) {
            throw new \RuntimeException(\sprintf(
                'The file "%s" must return an instance of %s, got %s.',
                $this->config->bootstrapFile(),
                Application::class,
                \get_debug_type($application),
            ));
        }

        $application->make(ConsoleKernel::class)->bootstrap();

        foreach ($this->config->config as $key => $value) {
            $application['config']->set($key, $value);
        }

        return $this->application = $application;
    }

    /**
     * The application booted for the currently running test.
     *
     * @throws \RuntimeException If no application is booted, e.g. a Laravel attribute
     *         interceptor is used in a suite without the {@see \Testo\Bridge\Laravel\LaravelPlugin}.
     */
    public function current(): Application
    {
        return $this->application ?? throw new \RuntimeException(
            'The Laravel application is not booted. Is the LaravelPlugin registered for this test suite?',
        );
    }

    /**
     * Drop the reference to the booted application.
     */
    public function flush(): void
    {
        $this->application = null;
    }
}
