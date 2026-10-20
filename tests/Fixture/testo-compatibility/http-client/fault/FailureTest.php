<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\HttpClientFault;

use Illuminate\Foundation\Application;
use Laratesto\Laratesto;
use Laratesto\Testing\LaravelTestCase;
use Testo\Assert;
use Testo\Test;

final class FailureTest extends LaravelTestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/laravel/bootstrap/app.php';
        if (getenv('LARATESTO_HTTP_FAULT') === 'boot') {
            throw new \RuntimeException('primary boot fault');
        }
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (getenv('LARATESTO_HTTP_FAULT') === 'cleanup') {
            $app['db']->extend('sqlite', static fn($config, $name) => new class(new \PDO('sqlite::memory:'), config: ['name' => $name] + $config) extends \Illuminate\Database\SQLiteConnection {
                public function disconnect(): void { throw new \RuntimeException('secondary cleanup fault'); }
            });
        }
        return $app;
    }

    public function setLaravelApplication(\Illuminate\Contracts\Foundation\Application $application): void
    {
        parent::setLaravelApplication($application);
        if (getenv('LARATESTO_HTTP_FAULT') === 'injection') {
            throw new \RuntimeException('primary injection fault');
        }
    }

    protected function setUpLaravel(): void
    {
        $GLOBALS['http_fault_client'] = Laratesto::http();
        if (getenv('LARATESTO_HTTP_FAULT') === 'setup') {
            throw new \RuntimeException('primary setup fault');
        }
    }

    #[Test]
    public function failure(): void
    {
        app('db')->connection()->getPdo();
        Assert::true(false, 'primary body fault');
    }

    protected function tearDownLaravel(): void
    {
        Assert::same($GLOBALS['http_fault_client'], Laratesto::http());
        throw new \RuntimeException('secondary teardown fault');
    }
}

#[Test]
function followingTest(): void
{
    Assert::same(Laratesto::http(), app(\Laratesto\Testing\HttpClient::class));
    if (isset($GLOBALS['http_fault_client'])) {
        try {
            $GLOBALS['http_fault_client']->get('/');
            Assert::fail('The failed execution client must be revoked.');
        } catch (\LogicException $failure) {
            Assert::string($failure->getMessage())->contains('no longer active');
        }
    }
}
