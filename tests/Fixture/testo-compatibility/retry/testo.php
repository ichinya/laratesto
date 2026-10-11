<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/events.php';
return new \Testo\Application\Config\ApplicationConfig(suites: [
    new \Testo\Application\Config\SuiteConfig(name: 'Compatibility', location: [__DIR__.'/cases'], plugins: [
        new \Testo\Bridge\Mockery\MockeryPlugin(),
        new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(basePath: dirname(__DIR__).'/app')),
    ]),
]);
