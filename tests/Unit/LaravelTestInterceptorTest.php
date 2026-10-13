<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Internal\Path;
use Laratesto\Config\LaravelConfig;
use Laratesto\Pipeline\LaravelTestInterceptor;
use Laratesto\Runtime\LaravelApplicationFactory;
use Laratesto\Runtime\LaravelStateCleaner;
use Testo\Assert;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Definition\CaseDefinition;
use Testo\Core\Definition\TestDefinition;
use Testo\Core\Context\Identity\SuiteIdentity;
use Testo\Core\Value\Status;
use Testo\Core\Value\CaseInstance;
use Testo\Test;

final class LaravelTestInterceptorTest
{
    #[Test]
    public function bootstrapFileThrowingBeforeReturnStillCleansItsPartialApplication(): void
    {
        $factory = new LaravelApplicationFactory(self::fixtureConfig('testo-compatibility/lifecycle-faults/broken-app'));
        $interceptor = new LaravelTestInterceptor($factory, new LaravelStateCleaner());
        $result = $interceptor->runTest(self::info(), static fn() => throw new \LogicException('must not run'));
        Assert::same(Status::Aborted, $result->status);
        Assert::same('primary bootstrap file fault', $result->failure?->getMessage());
        Assert::null($factory->acquired());
        Assert::null(\Illuminate\Support\Facades\Facade::getFacadeApplication());
        Assert::false(\Illuminate\Container\Container::getInstance()->bound('fault.partial'));
        $queue = new class extends \Illuminate\Queue\SyncQueue {
            public function payload(): array { return json_decode($this->createPayload('Example@handle', 'default'), true); }
        };
        Assert::false(isset($queue->payload()['fault_hook']));
    }

    #[Test]
    public function failureBeforeApplicationCreationDoesNotFlushTheEarlierContainer(): void
    {
        $earlier = new \Illuminate\Foundation\Application();
        $earlier->instance('caller.owned', true);
        $instance = new class implements CaseInstance {
            public function getInstance(): object { return $this; }
            public function hasInstance(): bool { return true; }
            public function createApplication(): never { throw new \RuntimeException('no new application'); }
        };
        $factory = new LaravelApplicationFactory(self::fixtureConfig('laravel'));
        $interceptor = new LaravelTestInterceptor($factory, new LaravelStateCleaner());
        $result = $interceptor->runTest(self::info($instance), static fn() => throw new \LogicException('must not run'));
        Assert::same('no new application', $result->failure?->getMessage());
        Assert::true($earlier->bound('caller.owned'));
        Assert::null($factory->acquired());
        Assert::null(\Illuminate\Support\Facades\Facade::getFacadeApplication());
    }

    #[Test]
    public function partialSetupAndInjectionReleaseReferencesWithoutDuplicateUserHooks(): void
    {
        $run = \Laratesto\Tests\Support\TestoCompatibilityRunner::run('partial');
        Assert::same(1, $run['exit']);
        Assert::same(3, $run['json']['totals']['total']);
        Assert::same(1, $run['json']['totals']['skipped']);
        Assert::same(1, $run['json']['totals']['passed']);
        Assert::same(1, $run['json']['totals']['aborted']);
        $events = array_count_values(array_column($run['events'], 'event'));
        Assert::same(3, $events['acquired']);
        Assert::same(3, $events['booted']);
        Assert::same(2, $events['setup']);
        Assert::same(1, $events['teardown']);
        Assert::same(1, $events['body']);
        Assert::false(isset($events['unexpected body']));
        foreach ($run['events'] as $event) {
            if ($event['event'] === 'references') {
                Assert::true($event['cleared']);
            }
        }
    }

    #[Test]
    public function configurationFailureAfterCustomAcquisitionStillCleansTheApplication(): void
    {
        $instance = new class implements CaseInstance {
            public ?\Illuminate\Foundation\Application $application = null;
            public function getInstance(): object { return $this; }
            public function hasInstance(): bool { return true; }
            public function createApplication(): \Illuminate\Foundation\Application
            {
                $this->application = new \Illuminate\Foundation\Application();
                $this->application->instance('config', new class extends \Illuminate\Config\Repository {
                    public function set($key, $value = null) { throw new \RuntimeException('configuration failed'); }
                });
                return $this->application;
            }
        };
        $factory = new LaravelApplicationFactory(new LaravelConfig(basePath: dirname(__DIR__).'/Fixture/laravel', config: ['marker' => true]));
        $result = (new LaravelTestInterceptor($factory, new LaravelStateCleaner()))->runTest(self::info($instance), static fn() => throw new \LogicException('must not run'));
        Assert::same('configuration failed', $result->failure?->getMessage());
        Assert::null($factory->acquired());
        Assert::false($instance->application->bound('config'));
    }

    #[Test]
    public function partialKernelBootstrapStillFlushesTheAcquiredApplication(): void
    {
        $factory = new LaravelApplicationFactory(new LaravelConfig(basePath: dirname(__DIR__).'/Fixture/testo-compatibility/lifecycle/broken-app'));
        $interceptor = new LaravelTestInterceptor($factory, new LaravelStateCleaner());
        $called = false;
        $result = $interceptor->runTest(self::info(), static function () use (&$called): never { $called = true; throw new \LogicException('must not run'); });
        Assert::same(Status::Aborted, $result->status);
        Assert::same('partial kernel bootstrap', $result->failure?->getMessage());
        Assert::false($called);
        Assert::null($factory->acquired());
        Assert::null(\Illuminate\Support\Facades\Facade::getFacadeApplication());
    }

    #[Test]
    public function bootFailureReturnsAbortedResultWithRootCause(): void
    {
        $interceptor = self::brokenFixtureInterceptor();
        $info = self::info();

        $nextCalled = false;

        /** @var TestResult $result */
        $result = $interceptor->runTest(
            $info,
            static function () use (&$nextCalled): TestResult {
                $nextCalled = true;

                return new TestResult(info: self::info(), status: Status::Passed);
            },
        );

        Assert::same($result->status, Status::Aborted);
        Assert::false($nextCalled, 'The pipeline must not run when boot fails.');
        Assert::string($result?->failure?->getMessage())
            ->contains('broken bootstrap fixture');
    }

    #[Test]
    public function bootFailureCarriesTheOriginalExceptionClass(): void
    {
        $interceptor = self::brokenFixtureInterceptor();

        /** @var TestResult $result */
        $result = $interceptor->runTest(self::info(), static fn(): TestResult => new TestResult(
            info: self::info(),
            status: Status::Passed,
        ));

        Assert::instanceOf($result->failure, \RuntimeException::class);
    }

    #[Test]
    public function successfulBootRunsThePipelineAndFlushesTheFactory(): void
    {
        $factory = new LaravelApplicationFactory(self::fixtureConfig('laravel'));
        $interceptor = new LaravelTestInterceptor($factory, new LaravelStateCleaner());
        $info = self::info();

        /** @var TestResult $result */
        $result = $interceptor->runTest(
            $info,
            static fn(): TestResult => new TestResult(info: $info, status: Status::Passed),
        );

        Assert::same($result->status, Status::Passed);

        $stillBooted = true;

        try {
            $factory->current();
        } catch (\RuntimeException) {
            $stillBooted = false;
        }

        Assert::false($stillBooted, 'The factory must drop the application after the test.');
    }

    #[Test]
    public function outputCleanupPreservesEarlierFailuresAndReportsNewFailures(): void
    {
        $cleanupFailure = new \RuntimeException('output cleanup failed');
        $instance = new class($cleanupFailure) implements CaseInstance {
            public function __construct(private \Throwable $failure) {}
            public function getInstance(): object { return $this; }
            public function hasInstance(): bool { return true; }
            public function beginPhpUnitOutputCapture(): void {}
            public function closePhpUnitOutputCapture(): void { throw $this->failure; }
        };
        $info = self::info($instance);
        foreach ([Status::Passed, Status::Failed, Status::Aborted] as $status) {
            $original = $status === Status::Passed ? null : new \RuntimeException('original failure');
            $interceptor = new LaravelTestInterceptor(
                new LaravelApplicationFactory(self::fixtureConfig('laravel')),
                new LaravelStateCleaner(),
            );
            $result = $interceptor->runTest($info, static fn(): TestResult => new TestResult(
                info: $info, status: $status, failure: $original,
            ));
            Assert::same($original ?? $cleanupFailure, $result->failure);
            Assert::same($status === Status::Passed ? Status::Aborted : $status, $result->status);
        }

        $original = new \RuntimeException('pipeline failure');
        $caught = null;
        try {
            $interceptor->runTest($info, static function () use ($original): never { throw $original; });
        } catch (\Throwable $failure) {
            $caught = $failure;
        }
        Assert::same($original, $caught);
    }

    private static function brokenFixtureInterceptor(): LaravelTestInterceptor
    {
        return new LaravelTestInterceptor(
            new LaravelApplicationFactory(self::fixtureConfig('broken-laravel')),
            new LaravelStateCleaner(),
        );
    }

    private static function fixtureConfig(string $name): LaravelConfig
    {
        return new LaravelConfig(
            basePath: \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'Fixture' . \DIRECTORY_SEPARATOR . $name,
        );
    }

    private static function info(?CaseInstance $instance = null): TestInfo
    {
        return new TestInfo(
            name: 'example',
            caseInfo: new CaseInfo(
                definition: new CaseDefinition(
                    name: 'ExampleTest',
                    type: 'test',
                    file: Path::create(__FILE__),
                ),
                suiteIdentity: new SuiteIdentity('Unit'),
                instance: $instance,
            ),
            testDefinition: new TestDefinition(
                reflection: new \ReflectionFunction(static fn(): bool => true),
            ),
        );
    }
}
