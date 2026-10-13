<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Internal\Path;
use Laratesto\Pipeline\Internal\FailureResult;
use Laratesto\Pipeline\Internal\DatabaseTransactionScope;
use Laratesto\Pipeline\LaravelLifecycleInterceptor;
use Laratesto\Tests\Support\TestoCompatibilityRunner;
use Testo\Assert;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\Identity\SuiteIdentity;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Definition\CaseDefinition;
use Testo\Core\Definition\TestDefinition;
use Testo\Core\Value\Status;
use Testo\Core\Value\Summary;
use Testo\Test;

final class LaravelLifecycleInterceptorTest
{
    #[Test]
    public function failedInnerRollbackNeverRollsBackTheOuterTransaction(): void
    {
        $previousContainer = \Illuminate\Container\Container::getInstance();
        $application = new \Illuminate\Foundation\Application();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE probe (id INTEGER)');
        $failure = new \RuntimeException('rollback hook fault');
        $connection = new class($pdo, $failure) extends \Illuminate\Database\SQLiteConnection {
            public function __construct(\PDO $pdo, private \Throwable $failure) { parent::__construct($pdo); }
            public function rollBack($toLevel = null): void { throw $this->failure; }
        };
        $connection->setEventDispatcher(new \Illuminate\Events\Dispatcher($application));
        $application->instance('db', new class($connection) {
            public function __construct(private $connection) {}
            public function connection($name) { return $this->connection; }
        });
        try {
            $outer = new DatabaseTransactionScope($application, ['sqlite']);
            $outer->begin();
            $pdo->exec('INSERT INTO probe VALUES (1)');
            $inner = new DatabaseTransactionScope($application, ['sqlite']);
            $inner->begin();
            $caught = null;
            try { $inner->close(); } catch (\Throwable $error) { $caught = $error; }
            Assert::same($failure, $caught);
            Assert::true($pdo->inTransaction());
            Assert::same(2, $connection->transactionLevel());
            Assert::same(1, (int) $pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn());

            $caught = null;
            try { $outer->close(); } catch (\Throwable $error) { $caught = $error; }
            Assert::same($failure, $caught);
            Assert::false($pdo->inTransaction());
            Assert::same(0, $connection->transactionLevel());
            Assert::same(0, (int) $pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn());
        } finally {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            \Illuminate\Container\Container::setInstance($previousContainer);
        }
    }

    #[Test]
    public function allDatabaseStrategiesAreInsideNativeRetryAndRepeat(): void
    {
        $classes = [
            \Testo\Retry\Interceptor\RetryPolicyRunInterceptor::class => -200,
            \Testo\Repeat\Internal\RepeatInterceptor::class => -190,
            \Laratesto\Pipeline\LaravelTestInterceptor::class => -180,
            \Laratesto\Pipeline\RefreshDatabaseInterceptor::class => -170,
            \Laratesto\Pipeline\DatabaseMigrationsInterceptor::class => -170,
            \Laratesto\Pipeline\DatabaseTruncationInterceptor::class => -170,
            \Laratesto\Pipeline\DatabaseTransactionsInterceptor::class => 0,
            LaravelLifecycleInterceptor::class => 50000,
        ];
        foreach ($classes as $class => $order) {
            $options = (new \ReflectionClass($class))->getAttributes(\Testo\Pipeline\Attribute\InterceptorOptions::class)[0]->newInstance();
            Assert::same($order, $options->order, $class);
        }
    }

    #[Test]
    public function secondaryErrorsRetainPrimaryIdentityPayloadAndMetrics(): void
    {
        $info = self::info();
        foreach ([Status::Failed, Status::Error, Status::Aborted, Status::Cancelled, Status::Skipped] as $status) {
            foreach ([new \RuntimeException('secondary'), new \Testo\Core\Exception\SkipTest('secondary skip'), new \Testo\Core\Exception\CancelTest('secondary cancel')] as $secondary) {
                $primary = new \RuntimeException('primary');
                $result = new TestResult($info, $status, result: ['payload'], failure: $primary, attributes: ['marker' => 'retained'], summary: new Summary([$status->name => 1], ['assertions' => 7], 0.25));
                $actual = FailureResult::afterCleanup($result, $secondary, 'test cleanup');
                Assert::same($status, $actual->status);
                Assert::same($primary, $actual->failure);
                Assert::same($result->result, $actual->result);
                Assert::same($result->attributes, $actual->attributes);
                Assert::same($result->summary, $actual->summary);
                Assert::same(1, count($actual->messages));
            }
        }
        $successful = new TestResult($info, Status::Passed, result: ['payload'], summary: new Summary(['Passed' => 1], ['assertions' => 4]));
        $failure = new \RuntimeException('standalone cleanup');
        $actual = FailureResult::afterCleanup($successful, $failure, 'test cleanup');
        Assert::same(Status::Aborted, $actual->status);
        Assert::same($failure, $actual->failure);
        Assert::same(['payload'], $actual->result);
        Assert::same(4, $actual->summary->metric('assertions'));
        Assert::same(1, $actual->summary->count(Status::Aborted));
    }

    #[Test]
    public function teardownSkipCannotReplaceAnAlreadyFailedBodyInNativeReports(): void
    {
        $run = TestoCompatibilityRunner::run('lifecycle');
        Assert::same(1, $run['exit']);
        Assert::same(['total' => 2, 'assertions' => 2, 'failed' => 1, 'passed' => 1], $run['json']['totals']);
        Assert::string(json_encode($run['json']['failures']))->contains('primary body failure')->contains('secondary teardown skip');
        Assert::same(1, count($run['junit']->xpath('//testcase/failure')));
        Assert::same(0, count($run['junit']->xpath('//testcase/skipped')));
        Assert::string($run['junit']->asXML())->contains('secondary teardown skip');
    }

    #[Test]
    public function beginFailureReportsRollbackFailureAndUnwindsTheOtherConnection(): void
    {
        $prior = \Illuminate\Container\Container::getInstance();
        try {
        $application = new \Illuminate\Foundation\Application();
        $good = new \Illuminate\Database\SQLiteConnection(new \PDO('sqlite::memory:'));
        $bad = new class(new \PDO('sqlite::memory:')) extends \Illuminate\Database\SQLiteConnection {
            public function rollBack($toLevel = null) { throw new \RuntimeException('secondary rollback'); }
        };
        $dispatcher = new \Illuminate\Events\Dispatcher($application);
        $good->setEventDispatcher($dispatcher);
        $bad->setEventDispatcher($dispatcher);
        $application->instance('db', new class($good, $bad) {
            public function __construct(private $good, private $bad) {}
            public function connection($name) { return match ($name) { 'good' => $this->good, 'bad' => $this->bad, default => throw new \RuntimeException('primary begin') }; }
        });
        $diagnostics = [];
        $scope = new DatabaseTransactionScope($application, ['good', 'bad', 'missing'], onSecondaryFailure: static function (\Throwable $failure) use (&$diagnostics): void { $diagnostics[] = $failure->getMessage(); });
        $caught = null;
        try { $scope->begin(); } catch (\Throwable $failure) { $caught = $failure; }
        Assert::same('primary begin', $caught?->getMessage());
        Assert::same(['secondary rollback'], $diagnostics);
        Assert::same(0, $good->transactionLevel());
        Assert::false($application->bound('db.transactions'));
        $scope->closeQuietly();
        Assert::same(1, count($diagnostics), 'Closing an owned scope twice must remain inert.');
        } finally {
            \Illuminate\Container\Container::setInstance($prior);
        }
    }

    private static function info(): TestInfo
    {
        return new TestInfo(name: 'example', caseInfo: new CaseInfo(
            definition: new CaseDefinition(name: 'Example', type: 'test', file: Path::create(__FILE__)),
            suiteIdentity: new SuiteIdentity('Unit'),
        ), testDefinition: new TestDefinition(reflection: new \ReflectionFunction(static fn() => null)));
    }
}
