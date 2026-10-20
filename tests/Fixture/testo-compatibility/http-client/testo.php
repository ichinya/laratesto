<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/events.php';
require_once __DIR__.'/state.php';
$fault = getenv('LARATESTO_HTTP_FAULT') ?: null;
$suites = [];
foreach ($fault === null ? ['primary' => 'cases', 'secondary' => 'second'] : ['fault' => 'fault'] as $name => $location) {
    $suites[] = new \Testo\Application\Config\SuiteConfig(name: $name, location: [__DIR__.'/'.$location], plugins: [
        new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(
            basePath: dirname(__DIR__, 2).'/laravel',
            config: ['app.name' => $name, 'session.driver' => 'array', 'database.connections.sqlite.database' => ':memory:'],
        )),
        new class implements \Testo\Common\PluginConfigurator {
            public function configure(\Internal\Container\Container $container): void
            {
                $container->get(\Testo\Common\EventListenerCollector::class)->addListener(
                    \Testo\Event\Test\TestPipelineFinished::class,
                    static function (): void {
                        try {
                            \Laratesto\Laratesto::http();
                            $closed = false;
                        } catch (\LogicException) {
                            $closed = true;
                        }
                        \Laratesto\Tests\Fixture\Compatibility\event('closed', ['closed' => $closed]);
                    },
                );
            }
        },
    ]);
}
return new \Testo\Application\Config\ApplicationConfig(suites: $suites);
