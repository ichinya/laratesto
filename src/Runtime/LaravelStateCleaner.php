<?php

declare(strict_types=1);

namespace Laratesto\Runtime;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Bootstrap\AboutCommand;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Mail\Markdown;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\Queue;
use Illuminate\Support\EncodedHtmlString;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Lottery;
use Illuminate\Support\Once;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use Illuminate\View\Component;

/**
 * Resets Laravel state after every test.
 *
 * The static resets are ported from the framework's own test teardown
 * (`Illuminate\Foundation\Testing\TestCase::tearDownTheTestEnvironment()`)
 * so a Testo process does not leak state between tests. Because every test
 * boots a fresh application, container-level state dies with the old instance;
 * only process-global static state needs an explicit reset.
 *
 * @api
 */
final class LaravelStateCleaner
{
    public function __construct(private readonly ?\Testo\Common\Messenger $messenger = null) {}

    /**
     * Static state resets: [class, method].
     *
     * Every entry is guarded by `method_exists()` because the bridge supports
     * multiple framework versions and some resets were added later than others.
     */
    private const RESETS = [
        [AboutCommand::class, 'flushState'],
        [ArtisanApplication::class, 'forgetBootstrappers'],
        [Component::class, 'flushCache'],
        [Component::class, 'forgetComponentsResolver'],
        [Component::class, 'forgetFactory'],
        [ConvertEmptyStringsToNull::class, 'flushState'],
        [EncodedHtmlString::class, 'flushState'],
        [Factory::class, 'flushState'],
        [FormRequest::class, 'flushState'],
        [HandleCors::class, 'flushState'],
        [JsonApiResource::class, 'flushState'],
        [JsonResource::class, 'flushState'],
        [Lottery::class, 'determineResultsNormally'],
        [Markdown::class, 'flushState'],
        [Once::class, 'flush'],
        [PreventRequestForgery::class, 'flushState'],
        [PreventRequestsDuringMaintenance::class, 'flushState'],
        [RegisterProviders::class, 'flushState'],
        [Str::class, 'resetFactoryState'],
        [TrimStrings::class, 'flushState'],
        [TrustHosts::class, 'flushState'],
        [TrustProxies::class, 'flushState'],
        [Validator::class, 'flushState'],
        [WorkCommand::class, 'flushState'],
    ];

    /**
     * @var list<array{class-string, non-empty-string, list<mixed>}>
     */
    private const RESETS_WITH_ARGUMENTS = [
        [Migrator::class, 'withoutMigrations', [[]]],
        [Queue::class, 'createPayloadUsing', [null]],
        [Sleep::class, 'fake', [false]],
    ];

    public function clean(?Application $application = null): void
    {
        $failures = [];
        $this->attempt('database disconnect', function () use ($application, &$failures): void {
            if ($application === null || !$application->bound('db')) {
                return;
            }
            foreach ($application['db']->getConnections() as $name => $connection) {
                $this->attempt('disconnect '.$name, $connection->disconnect(...), $failures);
            }
        }, $failures);
        if ($application !== null) {
            $this->attempt('application flush', $application->flush(...), $failures);
        }
        $this->attempt('facade instances', Facade::clearResolvedInstances(...), $failures);
        $this->attempt('facade application', static fn() => Facade::setFacadeApplication(null), $failures);
        $this->attempt('container application', static fn() => \Illuminate\Container\Container::setInstance(null), $failures);

        foreach (self::RESETS as [$class, $method]) {
            if (\method_exists($class, $method)) {
                $this->attempt($class.'::'.$method, static fn() => $class::$method(), $failures);
            }
        }

        foreach (self::RESETS_WITH_ARGUMENTS as [$class, $method, $arguments]) {
            if (\method_exists($class, $method)) {
                $this->attempt($class.'::'.$method, static fn() => $class::$method(...$arguments), $failures);
            }
        }

        // Optional dependencies of the framework itself.
        if (\class_exists(Carbon::class)) {
            $this->attempt('Carbon clock', Carbon::setTestNow(...), $failures);
        }
        if (\class_exists(CarbonImmutable::class)) {
            $this->attempt('CarbonImmutable clock', CarbonImmutable::setTestNow(...), $failures);
        }
        foreach (array_slice($failures, 1) as [$stage, $failure]) {
            \Laratesto\Pipeline\Internal\FailureResult::report($failure, $stage, $this->messenger);
        }
        if ($failures !== []) {
            throw $failures[0][1];
        }
    }

    /**
     * Collect a cleanup failure while allowing the remaining stages to run.
     */
    private function attempt(string $stage, callable $action, array &$failures): void
    {
        \Laratesto\Pipeline\Internal\FailureResult::trace('cleanup '.$stage, $this->messenger);
        try {
            $action();
        } catch (\Throwable $failure) {
            $failures[] = [$stage, $failure];
        }
    }
}
