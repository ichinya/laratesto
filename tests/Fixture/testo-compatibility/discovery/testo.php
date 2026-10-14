<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/events.php';

$include = [__DIR__.'/cases'];
if (getenv('LARATESTO_DISCOVERY_INCLUDE_FILE') === '1') {
    $include[] = __DIR__.'/cases/blocked/ExcludedTest.php';
}

return new \Testo\Application\Config\ApplicationConfig(
    suites: [new \Testo\Application\Config\SuiteConfig(
        name: 'Discovery',
        location: new \Testo\Application\Config\FinderConfig(
            include: $include,
            exclude: [__DIR__.'/cases/blocked'],
        ),
        plugins: [new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(basePath: dirname(__DIR__).'/app'))],
    )],
);
