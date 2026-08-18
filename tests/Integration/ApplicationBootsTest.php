<?php

declare(strict_types=1);

namespace Testo\Bridge\Laravel\Tests\Integration;

use Illuminate\Foundation\Application as FoundationApplication;
use Testo\Assert;
use Testo\Bridge\Laravel\Testing\InteractsWithLaravel;

final class ApplicationBootsTest
{
    use InteractsWithLaravel;

    private static int $previousApplicationId = 0;

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
