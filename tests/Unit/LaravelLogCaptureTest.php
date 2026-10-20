<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Laratesto\Config\LaravelConfig;
use Laratesto\Runtime\LaravelLogCapture;
use Testo\Application\Internal\MessengerHub;
use Testo\Assert;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\Identity\SuiteIdentity;
use Testo\Core\Context\TestInfo;
use Testo\Core\Definition\CaseDefinition;
use Testo\Core\Definition\TestDefinition;
use Testo\Core\Log\Level;
use Testo\Event\Message\MessageReceived;
use Testo\Test;

final class LaravelLogCaptureTest
{
    #[Test]
    public function disabledCaptureNeverResolvesTheEventDispatcher(): void
    {
        self::withApplication(function ($app): void {
            $app->forgetInstance('events');
            $app->bind('events', static fn() => throw new \LogicException('must not resolve'));
            $messenger = self::messenger();
            $capture = new LaravelLogCapture(new LaravelConfig(__DIR__, includeSqlBindings: true, includeLogContext: true), $messenger);
            $capture->start($app, self::info());
            $capture->stop();
            Assert::false($app->resolved('events'));
            Assert::same(0, count($messenger->getMessages()));
        });
    }

    #[Test]
    public function queryMetadataAndLogLevelsAreCapturedWithoutSensitiveValues(): void
    {
        self::withApplication(function ($app, $events): void {
            $messenger = self::messenger();
            $capture = new LaravelLogCapture(new LaravelConfig(__DIR__, captureSql: true, captureLogs: true), $messenger);
            $capture->start($app, self::info());
            $events->dispatch(self::query());
            $events->dispatch(new MessageLogged('warning', 'application message', ['token' => 'private-context']));
            $capture->stop();
            [$sql, $log] = $messenger->getMessages()->all();
            Assert::same('sql', $sql->channel);
            Assert::same(Level::Debug, $sql->level);
            Assert::same(['execution' => 1, 'sql' => 'select ?', 'connection' => 'reporting', 'time_ms' => 1.25], $sql->context);
            Assert::string($sql->content)->contains('[reporting] 1.250 ms select ?')->notContains('private-binding');
            Assert::same('laravel', $log->channel);
            Assert::same(Level::Warning, $log->level);
            Assert::same(['execution' => 1], $log->context);
            Assert::string($log->content)->contains('warning: application message')->notContains('private-context')->endsWith("\n");
        });
    }

    #[Test]
    public function optionalPayloadsPreserveOriginalContextAndApplicationLogging(): void
    {
        self::withApplication(function ($app, $events): void {
            $messenger = self::messenger();
            $capture = new LaravelLogCapture(new LaravelConfig(__DIR__, captureSql: true, captureLogs: true, includeSqlBindings: true, includeLogContext: true), $messenger);
            $handler = new \Monolog\Handler\TestHandler();
            $logger = new \Illuminate\Log\Logger(new \Monolog\Logger('application', [$handler]), $events);
            $capture->start($app, self::info());
            $events->dispatch(self::query());
            foreach (Level::cases() as $level) {
                $logger->log($level->value, 'message {execution}', ['execution' => 'user-context']);
            }
            $capture->stop();
            $messages = $messenger->getMessages()->all();
            Assert::same(9, count($messages));
            Assert::same(['private-binding'], $messages[0]->context['bindings']);
            Assert::string($messages[0]->content)->contains('bindings=["private-binding"]');
            foreach (Level::cases() as $index => $level) {
                $message = $messages[$index + 1];
                Assert::same($level, $message->level);
                Assert::same(['execution' => 'user-context'], $message->context['context']);
                Assert::same(1, $message->context['execution']);
                Assert::string($message->content)->contains('message {execution}')->contains('context={"execution":"user-context"}');
            }
            Assert::same(8, count($handler->getRecords()));
            Assert::same(['execution' => 'user-context'], $handler->getRecords()[0]->context);
        });
    }

    #[Test]
    public function reusedDispatchersDoNotDuplicateListenersOrKeepDisposedApplications(): void
    {
        self::withApplication(function ($app, $events): void {
            $messenger = self::messenger();
            $capture = new LaravelLogCapture(new LaravelConfig(__DIR__, captureLogs: true), $messenger);
            $observed = 0;
            $events->listen(MessageLogged::class, static function () use (&$observed): void { ++$observed; });
            $info = self::info();
            for ($i = 1; $i <= 2; ++$i) {
                $capture->start($app, $info);
                $events->dispatch(new MessageLogged('info', 'active'));
                $capture->stop();
                $events->dispatch(new MessageLogged('info', 'outside execution'));
            }
            Assert::same(2, count($events->getRawListeners()[MessageLogged::class]));
            Assert::same(4, $observed, 'Application listeners must remain installed.');
            Assert::same([1, 2], array_map(static fn($message) => $message->context['execution'], $messenger->getMessages()->all()));

            $new = new Dispatcher();
            $app->instance('events', $new);
            $capture->start($app, self::info());
            $events->dispatch(new MessageLogged('info', 'old dispatcher'));
            $new->dispatch(new MessageLogged('info', 'new dispatcher'));
            $capture->stop();
            Assert::same(3, count($messenger->getMessages()));
            Assert::same(1, $messenger->getMessages()->all()[2]->context['execution']);
            $reference = \WeakReference::create($new);
            $app->forgetInstance('events');
            unset($new);
            gc_collect_cycles();
            Assert::null($reference->get(), 'Capture must not retain a disposed dispatcher.');
        });
    }

    #[Test]
    public function reentrantLogsAreIgnoredAndDeliveryFailuresStayDiagnostic(): void
    {
        self::withApplication(function ($app, $events): void {
            $messenger = self::messenger(static function (MessageReceived $event) use ($events): void {
                if ($event->message->channel === 'laravel') {
                    $events->dispatch(new MessageLogged('info', 'recursive message'));
                    throw new \RuntimeException('delivery failed');
                }
            });
            $capture = new LaravelLogCapture(new LaravelConfig(__DIR__, captureLogs: true), $messenger);
            $capture->start($app, self::info());
            $events->dispatch(new MessageLogged('info', 'original message'));
            $events->dispatch(new MessageLogged('info', 'disabled after failure'));
            $messages = $messenger->getMessages()->all();
            Assert::same(2, count($messages));
            Assert::same('laravel', $messages[0]->channel);
            Assert::same('stderr', $messages[1]->channel);
            Assert::string($messages[1]->content)->contains('log capture delivery')->contains('delivery failed');
        });
    }

    #[Test]
    public function failedRegistrationCanResumeWithoutDuplicatingTheFirstListener(): void
    {
        self::withApplication(function ($app): void {
            $events = new class extends Dispatcher {
                public bool $fail = true;
                public function listen($events, $listener = null)
                {
                    if ($this->fail && $events === MessageLogged::class) {
                        throw new \RuntimeException('registration failed');
                    }
                    parent::listen($events, $listener);
                }
            };
            $app->instance('events', $events);
            $messenger = self::messenger();
            $capture = new LaravelLogCapture(new LaravelConfig(__DIR__, captureSql: true, captureLogs: true), $messenger);
            $capture->start($app, self::info());
            $events->dispatch(self::query());
            Assert::same(1, count($messenger->getMessages()));
            Assert::string($messenger->getMessages()->all()[0]->content)->contains('registration failed');
            $events->fail = false;
            $capture->start($app, self::info());
            $events->dispatch(self::query());
            $events->dispatch(new MessageLogged('info', 'registered'));
            $capture->stop();
            Assert::same(3, count($messenger->getMessages()));
            Assert::same(1, count($events->getRawListeners()[\Illuminate\Database\Events\QueryExecuted::class]));
        });
    }

    private static function withApplication(\Closure $run): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $app = new Application();
        $events = new Dispatcher();
        $app->instance('events', $events);
        try {
            $run($app, $events);
        } finally {
            $app->flush();
            \Illuminate\Container\Container::setInstance($previous);
        }
    }

    private static function messenger(?\Closure $listener = null): MessengerHub
    {
        return new MessengerHub(new class($listener) implements \Psr\EventDispatcher\EventDispatcherInterface {
            public function __construct(private ?\Closure $listener) {}
            public function dispatch(object $event): object
            {
                if ($event instanceof MessageReceived) {
                    ($this->listener)?->__invoke($event);
                }
                return $event;
            }
        });
    }

    private static function query(): \Illuminate\Database\Events\QueryExecuted
    {
        return new \Illuminate\Database\Events\QueryExecuted('select ?', ['private-binding'], 1.25,
            new \Illuminate\Database\SQLiteConnection(new \PDO('sqlite::memory:'), config: ['name' => 'reporting']));
    }

    private static function info(): TestInfo
    {
        return new TestInfo(name: 'example', caseInfo: new CaseInfo(
            definition: new CaseDefinition(name: 'Example', type: 'test', file: \Internal\Path::create(__FILE__)),
            suiteIdentity: new SuiteIdentity('Unit'),
        ), testDefinition: new TestDefinition(reflection: new \ReflectionFunction(static fn() => null)));
    }
}
