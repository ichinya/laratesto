<?php

declare(strict_types=1);

return new \Testo\Application\Config\ApplicationConfig(suites: [
    new \Testo\Application\Config\SuiteConfig(name: 'CommitBoundary', location: [__DIR__.'/cases'], plugins: [
        new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(
            basePath: dirname(__DIR__).'/laravel',
            config: [
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => getenv('LARATESTO_CRASH_DATABASE'),
            ],
        )),
    ]),
]);
