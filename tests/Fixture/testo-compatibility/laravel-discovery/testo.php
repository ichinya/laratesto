<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/events.php';

return new \Testo\Application\Config\ApplicationConfig(suites: [
    new \Testo\Application\Config\SuiteConfig(
        name: 'Laravel discovery',
        location: [__DIR__.'/cases'],
        plugins: [new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(basePath: dirname(__DIR__).'/app'))],
    ),
]);
