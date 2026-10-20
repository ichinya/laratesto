<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Laratesto\Config\LaravelConfig;
use Laratesto\Laratesto;
use Laratesto\Pipeline\LaravelTestInterceptor;
use Laratesto\Runtime\HttpTestContext;
use Laratesto\Runtime\LaravelApplicationFactory;
use Laratesto\Runtime\LaravelStateCleaner;
use Laratesto\Testing\HttpClient;
use Testo\Assert;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\Identity\SuiteIdentity;
use Testo\Core\Context\TestInfo;
use Testo\Core\Definition\CaseDefinition;
use Testo\Core\Definition\TestDefinition;
use Testo\Core\Value\Status;
use Testo\Test;

final class HttpClientContextTest
{
    #[Test]
    public function clientsAreBoundToAnExecutionRatherThanTheNextGlobalApplication(): void
    {
        $previous = Container::getInstance();
        self::rejects(Laratesto::http(...), 'active LaravelPlugin');
        $first = HttpTestContext::enter();
        try {
            self::rejects(Laratesto::http(...), 'bootstrap');
            $app = new Application();
            $first->activate($app);
            $client = Laratesto::http();
            Assert::same($client, $app->make(HttpClient::class));
            Assert::same($client, $client->withHeader('X-Test', 'value'));
            self::rejects(HttpTestContext::enter(...), 'already active');
            Assert::same($client, Laratesto::http());
            $first->close();
            self::rejects(Laratesto::http(...), 'active LaravelPlugin');
            $second = HttpTestContext::enter();
            try {
                $second->activate(new Application());
                Assert::notSame($client, Laratesto::http());
                self::rejects(static fn() => $client->get('/'), 'no longer active');
                self::rejects(static fn() => $client->withHeader('X-Leak', 'old'), 'no longer active');
                self::rejects(static fn() => $client->withSession(['old' => true]), 'no longer active');
                $first->close();
                Assert::same($second->client(), Laratesto::http(), 'Closing an old context cannot clear a new one.');
            } finally {
                $second->close();
            }
        } finally {
            $first->close();
            Container::setInstance($previous);
        }
    }

    #[Test]
    public function anExternallyReplacedApplicationIsNotSilentlyUsedByTheClient(): void
    {
        $previous = Container::getInstance();
        $context = HttpTestContext::enter();
        try {
            $context->activate(new Application());
            $client = Laratesto::http();
            $replacement = new Application();
            self::rejects(static fn() => $client->get('/'), 'application has changed');
            self::rejects(Laratesto::http(...), 'application has changed');
            Assert::same($replacement, Container::getInstance());
        } finally {
            $context->close();
            Container::setInstance($previous);
        }
    }

    #[Test]
    public function retainingAClosedClientDoesNotRetainItsApplication(): void
    {
        $previous = Container::getInstance();
        $context = HttpTestContext::enter();
        try {
            $app = new Application();
            $context->activate($app);
            $client = Laratesto::http();
            $weak = \WeakReference::create($app);
            $context->close();
            $app->flush();
            Container::setInstance($previous);
            unset($app);
            gc_collect_cycles();
            Assert::null($weak->get());
            self::rejects(static fn() => $client->get('/'), 'no longer active');
        } finally {
            $context->close();
            Container::setInstance($previous);
        }
    }

    #[Test]
    public function nestedInterceptorsAbortBeforeBootOrCleanupCanTouchTheOuterApplication(): void
    {
        $previous = Container::getInstance();
        $context = HttpTestContext::enter();
        try {
            $app = new Application();
            $app->instance('outer.marker', true);
            $context->activate($app);
            $client = Laratesto::http();
            $factory = new LaravelApplicationFactory(new LaravelConfig(__DIR__));
            $interceptor = new LaravelTestInterceptor($factory, new LaravelStateCleaner());
            $info = new TestInfo(name: 'nested', caseInfo: new CaseInfo(
                definition: new CaseDefinition(name: 'Nested', type: 'test', file: \Internal\Path::create(__FILE__)),
                suiteIdentity: new SuiteIdentity('Unit'),
            ), testDefinition: new TestDefinition(reflection: new \ReflectionFunction(static fn() => null)));
            $result = $interceptor->runTest($info, static fn() => throw new \LogicException('Nested body must not run.'));
            Assert::same(Status::Aborted, $result->status);
            Assert::string($result->failure->getMessage())->contains('already active');
            Assert::null($factory->acquired());
            Assert::true($app->make('outer.marker'));
            Assert::same($client, Laratesto::http());
        } finally {
            $context->close();
            Container::setInstance($previous);
        }
    }

    private static function rejects(callable $call, string $message): void
    {
        try {
            $call();
        } catch (\LogicException $failure) {
            Assert::string($failure->getMessage())->contains($message);
            return;
        }
        Assert::fail('Expected an HTTP test context error.');
    }
}
