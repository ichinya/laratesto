<?php

declare(strict_types=1);

use Laratesto\Config\LaravelConfig;
use Laratesto\LaravelPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;
use Testo\Bridge\Mockery\MockeryPlugin;
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
                new MockeryPlugin(),
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
