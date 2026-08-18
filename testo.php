<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;
use Testo\Bridge\Laravel\Config\LaravelConfig;
use Testo\Bridge\Laravel\LaravelPlugin;
use Testo\Convention\NamingConventionPlugin;

return new ApplicationConfig(
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: ['tests/Unit'],
        ),
        new SuiteConfig(
            name: 'Laravel',
            location: ['tests/Integration'],
            plugins: [
                new NamingConventionPlugin(),
                new LaravelPlugin(
                    new LaravelConfig(
                        basePath: __DIR__ . \DIRECTORY_SEPARATOR . 'tests' . \DIRECTORY_SEPARATOR . 'Fixture' . \DIRECTORY_SEPARATOR . 'laravel',
                        config: [
                            'app.name' => 'Laratesto Fixture',
                        ],
                    ),
                ),
            ],
        ),
    ],
);
