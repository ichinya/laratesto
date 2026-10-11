<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Illuminate\Foundation\Application as FoundationApplication;
use Testo\Assert;
use Laratesto\Testing\InteractsWithLaravel;

final class ApplicationBootsTest
{
    use InteractsWithLaravel;

    private static int $previousApplicationId = 0;

    public function testNativeRetryAndRepeatOwnFreshApplicationsAndKeepFailures(): void
    {
        $run = \Laratesto\Tests\Support\TestoCompatibilityRunner::run('retry');
        Assert::same(1, $run['exit']);
        Assert::same(8, $run['json']['totals']['total']);
        Assert::same(5, $run['json']['totals']['passed']);
        Assert::same(1, $run['json']['totals']['flaky']);
        Assert::same(1, $run['json']['totals']['failed']);
        Assert::same(1, $run['json']['totals']['skipped']);
        $counts = array_count_values(array_column($run['events'], 'event'));
        foreach (['acquired', 'booted', 'setup', 'body', 'teardown'] as $phase) {
            Assert::same(16, $counts[$phase], $phase);
        }
        $bodies = array_filter($run['events'], static fn($event) => $event['event'] === 'body');
        Assert::same(16, count(array_unique(array_column($bodies, 'owner'))));
        Assert::string(json_encode($run['json']['failures']))->contains('primary exhausted body failure')->contains('secondary retry teardown');
        $xml = $run['junit']->asXML();
        Assert::string($xml)->contains('flakyFailure')->contains('rerunFailure')->contains('secondary retry teardown');
        Assert::same(8, count($run['junit']->xpath('//testcase')));
        // 16 executions × (4 setup + 3 body + 1 Mockery verification),
        // three deliberate failures and six stacked-transaction assertions.
        Assert::same(137, $run['json']['totals']['assertions']);
    }

    public function testNativeCleanupFailuresAreUnsuccessfulAndDoNotRetryAborts(): void
    {
        $run = \Laratesto\Tests\Support\TestoCompatibilityRunner::run('retry-cleanup');
        Assert::same(1, $run['exit']);
        Assert::same(['total' => 4, 'assertions' => 4, 'aborted' => 2, 'passed' => 2], $run['json']['totals']);
        Assert::same(2, count($run['junit']->xpath('//testcase/error')));
        $counts = array_count_values(array_column($run['events'], 'event'));
        Assert::same(4, $counts['acquired']);
        Assert::same(4, $counts['body']);
        foreach ($run['events'] as $event) {
            if ($event['event'] === 'clean') { Assert::true($event['facade']); }
        }
        Assert::string(json_encode($run['json']['failures']))->contains('standalone teardown error')->contains('standalone disconnect error');
    }

    public function testBootsTheFramework(): void
    {
        $app = $this->app();

        Assert::instanceOf($app, FoundationApplication::class);
        Assert::true($app->bound('config'));
        Assert::true($app->bound('db'));
    }

    public function testLoadsTestingEnvironmentAndConfiguration(): void
    {
        $app = $this->app();

        Assert::same($app->environment(), 'testing');
        Assert::same($app['config']->get('database.default'), 'sqlite');
        Assert::same($app['config']->get('database.connections.sqlite.database'), ':memory:');
    }

    public function testApplicationIsRecreatedForEveryTest(): void
    {
        $id = spl_object_id($this->app());

        $previous = self::$previousApplicationId;
        self::$previousApplicationId = $id;

        // The first run only records the id; every following test must get a new application.
        Assert::true(
            $previous === 0 || $previous !== $id,
            'Every test must receive a freshly booted application.',
        );
    }

    public function testGlobalHelpersAndFacadesAreAvailable(): void
    {
        Assert::instanceOf(app(), FoundationApplication::class);
        Assert::same(config('database.default'), 'sqlite');
    }

    public function testAppliesConfigurationOverrides(): void
    {
        Assert::same($this->app()['config']->get('app.name'), 'Laratesto Fixture');
    }

    public function testResolvesServicesFromTheContainer(): void
    {
        $dispatcher = $this->make(\Illuminate\Contracts\Events\Dispatcher::class);

        Assert::instanceOf($dispatcher, \Illuminate\Events\Dispatcher::class);
    }
}
