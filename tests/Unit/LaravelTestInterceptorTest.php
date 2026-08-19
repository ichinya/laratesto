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
use Testo\Test;

final class LaravelTestInterceptorTest
{
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

    private static function info(): TestInfo
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
            ),
            testDefinition: new TestDefinition(
                reflection: new \ReflectionFunction(static fn(): bool => true),
            ),
        );
    }
}
