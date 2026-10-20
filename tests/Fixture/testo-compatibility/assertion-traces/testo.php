<?php

declare(strict_types=1);

return new \Testo\Application\Config\ApplicationConfig(suites: [
    new \Testo\Application\Config\SuiteConfig(
        name: 'Assertion traces',
        location: [__DIR__.'/cases'],
        plugins: [new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(
            basePath: dirname(__DIR__, 2).'/laravel',
        ))],
    ),
]);
