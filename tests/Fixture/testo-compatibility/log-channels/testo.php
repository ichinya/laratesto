<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/events.php';

$mode = getenv('LARATESTO_LOG_MODE') ?: 'default';
return new \Testo\Application\Config\ApplicationConfig(suites: [
    new \Testo\Application\Config\SuiteConfig(name: 'Log channels', location: [__DIR__.'/cases'], plugins: [
        new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(
            basePath: dirname(__DIR__, 2).'/laravel',
            config: [
                'database.connections.sqlite.database' => ':memory:',
                'logging.default' => 'null',
                'logging.channels.null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class],
            ],
            captureSql: $mode !== 'disabled',
            captureLogs: $mode !== 'disabled',
            includeSqlBindings: $mode === 'details',
            includeLogContext: $mode === 'details',
        )),
        new class implements \Testo\Common\PluginConfigurator {
            public function configure(\Internal\Container\Container $container): void
            {
                $listeners = $container->get(\Testo\Common\EventListenerCollector::class);
                $listeners->addListener(\Testo\Event\Message\MessageReceived::class, static function ($event): void {
                    \Laratesto\Tests\Fixture\Compatibility\event('message', [
                        'test' => $event->identity?->fqn(),
                        'channel' => $event->message->channel,
                        'content' => $event->message->content,
                        'level' => $event->message->level->value,
                        'context' => $event->message->context,
                    ]);
                });
            }
        },
    ]),
]);
