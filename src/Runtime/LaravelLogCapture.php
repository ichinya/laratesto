<?php

declare(strict_types=1);

namespace Laratesto\Runtime;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Laratesto\Config\LaravelConfig;
use Laratesto\Pipeline\Internal\FailureResult;
use Testo\Common\Messenger;
use Testo\Core\Context\Identity\TestIdentity;
use Testo\Core\Context\TestInfo;
use Testo\Core\Log\Level;

/** @internal Forwards Laravel events into the active native Testo message scope. */
final class LaravelLogCapture
{
    /** @var \WeakMap<Dispatcher, array<class-string, true>> */
    private \WeakMap $listeners;

    /** @var \WeakMap<TestIdentity, int> */
    private \WeakMap $executions;

    /** @var \WeakReference<Dispatcher>|null */
    private ?\WeakReference $active = null;

    private int $execution = 0;
    private bool $writing = false;

    public function __construct(private readonly LaravelConfig $config, private readonly Messenger $messenger)
    {
        $this->listeners = new \WeakMap();
        $this->executions = new \WeakMap();
    }

    public function start(Application $application, TestInfo $info): void
    {
        $this->stop();
        if (!$this->config->captureSql && !$this->config->captureLogs) {
            return;
        }

        try {
            $dispatcher = $application->make('events');
            $reference = \WeakReference::create($dispatcher);
            $this->active = $reference;
            $this->execution = $this->executions[$info->identity] = ($this->executions[$info->identity] ?? 0) + 1;

            foreach ([QueryExecuted::class => $this->config->captureSql, MessageLogged::class => $this->config->captureLogs] as $event => $enabled) {
                $registered = $this->listeners[$dispatcher] ?? [];
                if (!$enabled || isset($registered[$event])) {
                    continue;
                }
                // The callback must not retain its dispatcher/application. Laravel's
                // public forget() removes application listeners too, so keep ours inert
                // outside an execution and register it only once per dispatcher.
                $dispatcher->listen($event, fn($message) => $this->receive($reference, $message));
                $registered[$event] = true;
                $this->listeners[$dispatcher] = $registered;
            }
        } catch (\Throwable $failure) {
            $this->stop();
            FailureResult::report($failure, 'log capture setup', $this->messenger);
        }
    }

    public function stop(): void
    {
        $this->active = null;
        $this->execution = 0;
    }

    /** @param \WeakReference<Dispatcher> $source */
    private function receive(\WeakReference $source, QueryExecuted|MessageLogged $event): void
    {
        if ($this->writing || $this->active === null || $source->get() === null || $source->get() !== $this->active->get()) {
            return;
        }

        $this->writing = true;
        try {
            if ($event instanceof QueryExecuted) {
                $context = ['execution' => $this->execution, 'sql' => $event->sql, 'connection' => $event->connectionName, 'time_ms' => $event->time];
                $content = sprintf('[execution %d] [%s] %.3f ms %s', $this->execution, $event->connectionName, $event->time, $event->sql);
                if ($this->config->includeSqlBindings) {
                    $context['bindings'] = $event->bindings;
                    $content .= ' bindings='.self::encode($event->bindings);
                }
                $this->messenger->log('sql', $content."\n", Level::Debug, $context);
            } else {
                $context = ['execution' => $this->execution];
                $content = sprintf('[execution %d] %s: %s', $this->execution, $event->level, $event->message);
                if ($this->config->includeLogContext) {
                    $context['context'] = $event->context;
                    $content .= ' context='.self::encode($event->context);
                }
                $this->messenger->log('laravel', $content."\n", Level::from($event->level), $context);
            }
        } catch (\Throwable $failure) {
            $this->stop();
            FailureResult::report($failure, 'log capture delivery', $this->messenger);
        } finally {
            $this->writing = false;
        }
    }

    private static function encode(array $values): string
    {
        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
