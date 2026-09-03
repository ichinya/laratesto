<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Internal\Path;
use Laratesto\Attribute\DatabaseTransactions;
use Laratesto\Attribute\RefreshDatabase;
use Laratesto\Config\LaravelConfig;
use Laratesto\Pipeline\DatabaseTransactionsInterceptor;
use Laratesto\Pipeline\RefreshDatabaseInterceptor;
use Laratesto\Runtime\LaravelApplicationFactory;
use Testo\Assert;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Definition\CaseDefinition;
use Testo\Core\Definition\TestDefinition;
use Testo\Core\Context\Identity\SuiteIdentity;
use Testo\Core\Value\Status;
use Testo\Test;

/**
 * Database interceptors must convert missing/unregistered Laravel state and
 * unresolvable connections into aborted test results carrying the original
 * exception instead of letting the bridge failure escape the interceptor.
 */
final class DatabaseInterceptorErrorBoundaryTest
{
    #[Test]
    public function refreshDatabaseWithoutBootedApplicationAbortsTheTest(): void
    {
        $interceptor = new RefreshDatabaseInterceptor(
            new RefreshDatabase(),
            new LaravelApplicationFactory(self::fixtureConfig()),
        );
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
        Assert::false($nextCalled, 'The pipeline must not run when the application is missing.');
        Assert::instanceOf($result->failure, \RuntimeException::class);
        Assert::string($result->failure?->getMessage())->contains('LaravelPlugin');
    }

    #[Test]
    public function databaseTransactionsWithoutBootedApplicationAbortsTheTest(): void
    {
        $interceptor = new DatabaseTransactionsInterceptor(
            new DatabaseTransactions(),
            new LaravelApplicationFactory(self::fixtureConfig()),
        );
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
        Assert::false($nextCalled, 'The pipeline must not run when the application is missing.');
        Assert::instanceOf($result->failure, \RuntimeException::class);
        Assert::string($result->failure?->getMessage())->contains('LaravelPlugin');
    }

    #[Test]
    public function refreshDatabaseWithUnresolvableConnectionAbortsTheTest(): void
    {
        $interceptor = new RefreshDatabaseInterceptor(
            new RefreshDatabase(connections: ['missing-connection']),
            self::bootedFixtureFactory(),
        );

        RefreshDatabaseState::$migrated = true;

        /** @var TestResult $result */
        $result = $interceptor->runTest(self::info(), static fn(): TestResult => new TestResult(
            info: self::info(),
            status: Status::Passed,
        ));

        Assert::same($result->status, Status::Aborted);
        Assert::instanceOf($result->failure, \InvalidArgumentException::class);
        Assert::string($result->failure?->getMessage())->contains('missing-connection');
        Assert::false(
            RefreshDatabaseState::$migrated,
            'A failed connection resolution must reset the migrated flag.',
        );
    }

    #[Test]
    public function databaseTransactionsWithUnresolvableConnectionAbortsTheTest(): void
    {
        $interceptor = new DatabaseTransactionsInterceptor(
            new DatabaseTransactions(connections: ['missing-connection']),
            self::bootedFixtureFactory(),
        );

        /** @var TestResult $result */
        $result = $interceptor->runTest(self::info(), static fn(): TestResult => new TestResult(
            info: self::info(),
            status: Status::Passed,
        ));

        Assert::same($result->status, Status::Aborted);
        Assert::instanceOf($result->failure, \InvalidArgumentException::class);
        Assert::string($result->failure?->getMessage())->contains('missing-connection');
    }

    #[Test]
    public function currentFailsWithAHintWhileNoApplicationIsBooted(): void
    {
        $factory = new LaravelApplicationFactory(self::fixtureConfig());

        $failed = false;

        try {
            $factory->current();
        } catch (\RuntimeException $exception) {
            $failed = true;
            Assert::string($exception->getMessage())->contains('LaravelPlugin');
        }

        Assert::true($failed, 'current() must fail while no application is booted.');
    }

    #[Test]
    public function currentFailsAgainAfterTheApplicationWasFlushed(): void
    {
        $factory = self::bootedFixtureFactory();
        $factory->flush();

        $failed = false;

        try {
            $factory->current();
        } catch (\RuntimeException) {
            $failed = true;
        }

        Assert::true($failed, 'current() must fail after the application was flushed.');
    }

    private static function bootedFixtureFactory(): LaravelApplicationFactory
    {
        $factory = new LaravelApplicationFactory(self::fixtureConfig());
        $factory->boot();

        return $factory;
    }

    private static function fixtureConfig(): LaravelConfig
    {
        return new LaravelConfig(
            basePath: \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'Fixture' . \DIRECTORY_SEPARATOR . 'laravel',
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
