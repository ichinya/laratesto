<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Laratesto\Console\TestProcessRunner;
use Laratesto\Testing\LaravelTestCase;
use Laratesto\Tests\Support\FakeTestProcessRunner;
use Testo\Assert;

final class HttpRequestsTest extends LaravelTestCase
{
    public function testGetRequestPassesThroughTheHttpKernel(): void
    {
        $this->get('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['laravel' => 'ok']);
    }

    public function testPostJsonSendsAnEncodedPayload(): void
    {
        $response = $this->postJson('/api/echo', ['message' => 'hello']);

        $response->assertOk();

        Assert::same($response->json(), [
            'received' => 'hello',
            'content_type' => 'application/json',
        ]);
    }

    public function testPostSendsFormParameters(): void
    {
        $response = $this->post('/api/echo', ['message' => 'form-data']);

        $response->assertOk();
        Assert::same($response->json()['received'], 'form-data');
    }

    public function testLaravelCompatibleRequestStateAndRedirectHelpers(): void
    {
        $this->withSession(['seeded' => 'yes'])
            ->get('/session/seeded')
            ->assertExactJson(['seeded' => 'yes']);

        $this->withCookie('manual', 'cookie-value')
            ->get('/cookie/read')
            ->assertExactJson(['cookie' => 'cookie-value']);

        $this->get('/redirect/start')->assertRedirect('/redirect/end');

        $this->followingRedirects()
            ->get('/redirect/start')
            ->assertOk()
            ->assertExactJson(['redirected' => true]);

        $this->get('/session/write')->assertOk();
        $this->withSession(['key' => 'overridden']);
        Assert::same($this->session()->get('key'), 'overridden');
        Assert::true($this->session()->isStarted());
        Assert::same($this->app()->make('session')->driver(), $this->session());
        $this->get('/session/read')->assertJsonPath('key', 'overridden');

        $this->withHeader('X-Test-Header', 'present');
        $this->withoutHeader('x-test-header');
        $this->get('/headers')->assertJsonPath('x-test-header', null);
    }

    public function testLaravelCompatibleResponseAndJsonHelpers(): void
    {
        $this->deleteJson('/api/delete', ['id' => 42])
            ->assertOk()
            ->assertExactJson(['deleted' => 42]);

        $this->get('/session/redirect')
            ->assertFound()
            ->assertSessionHas('notice', 'saved')
            ->assertSessionMissing('missing');

        $this->postJson('/session/flash', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->get('/view')->assertViewHas('value', 'bound');
    }

    public function testUnknownRouteReturnsNotFound(): void
    {
        $this->get('/api/nope')->assertStatus(404);
    }

    public function testArtisanCommandsAreExecutable(): void
    {
        $this->artisan('route:list')->assertExitCode(0);
    }

    public function testArtisanTestRunsTestoThenPrefersPestOverPhpUnit(): void
    {
        $binaries = $this->createRunnerBinaries(['testo', 'pest', 'phpunit']);
        $runner = new FakeTestProcessRunner([0, 0]);
        $this->app()->instance(TestProcessRunner::class, $runner);

        try {
            $this->artisan('test', [
                '--filter' => ['User'],
                '--testsuite' => ['Laravel'],
                '--path' => ['tests/Testo'],
                '--coverage' => true,
                '--testo-arg' => ['--json'],
                '--legacy-arg' => ['--colors=always'],
            ])
                ->assertSuccessful()
                ->expectsOutputToContain('Running Testo')
                ->expectsOutputToContain('Running Pest');

            Assert::same($runner->commands, [
                [
                    \PHP_BINARY,
                    $this->runnerBinaryPath('testo'),
                    'run',
                    '--filter=User',
                    '--suite=Laravel',
                    '--path=tests/Testo',
                    '--coverage',
                    '--json',
                ],
                [
                    \PHP_BINARY,
                    $this->runnerBinaryPath('pest'),
                    '--filter=User',
                    '--testsuite=Laravel',
                    '--coverage',
                    'tests/Testo',
                    '--colors=always',
                ],
            ]);
        } finally {
            $this->removeRunnerBinaries($binaries);
        }
    }

    public function testArtisanTestFallsBackToPhpUnitAndAggregatesFailures(): void
    {
        $binaries = $this->createRunnerBinaries(['testo', 'phpunit']);
        $runner = new FakeTestProcessRunner([5, 0]);
        $this->app()->instance(TestProcessRunner::class, $runner);

        try {
            $this->artisan('test')
                ->assertFailed()
                ->expectsOutputToContain('Testo failed with exit code 5.')
                ->expectsOutputToContain('Running PHPUnit');

            Assert::count($runner->commands, 2);
            Assert::same($runner->commands[1][1], $this->runnerBinaryPath('phpunit'));
        } finally {
            $this->removeRunnerBinaries($binaries);
        }
    }

    public function testArtisanTestFailFastStopsBeforeTheLegacyRunner(): void
    {
        $binaries = $this->createRunnerBinaries(['testo', 'pest']);
        $runner = new FakeTestProcessRunner([7, 0]);
        $this->app()->instance(TestProcessRunner::class, $runner);

        try {
            $this->artisan('test', ['--fail-fast' => true])->assertFailed();

            Assert::count($runner->commands, 1);
        } finally {
            $this->removeRunnerBinaries($binaries);
        }
    }

    public function testArtisanTestCanRunOnlyTheLegacyRunner(): void
    {
        $binaries = $this->createRunnerBinaries(['testo', 'phpunit']);
        $runner = new FakeTestProcessRunner([0]);
        $this->app()->instance(TestProcessRunner::class, $runner);

        try {
            $this->artisan('test', ['--legacy-only' => true])
                ->assertSuccessful()
                ->expectsOutputToContain('Running PHPUnit');

            Assert::count($runner->commands, 1);
            Assert::same($runner->commands[0][1], $this->runnerBinaryPath('phpunit'));
        } finally {
            $this->removeRunnerBinaries($binaries);
        }
    }

    public function testFailedArtisanCommandsAreAssertable(): void
    {
        $this->artisan('laratesto:migrate-phpunit', [
            'source' => 'tests/Unit/MissingTest.php',
            '--dry-run' => true,
        ])->assertFailed();
    }

    public function testPhpUnitMigrationCommandSupportsSafeDryRun(): void
    {
        $this->artisan('laratesto:migrate-phpunit', [
            'source' => 'tests/Unit/LegacyExampleTest.php',
            '--dry-run' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('dry-run: tests/Unit/LegacyExampleTest.php -> tests/Testo/Unit/LegacyExampleTest.php')
            ->expectsOutputToContain('Migration summary: 1 converted, 0 unchanged, 0 warnings, 0 failed.');

        Assert::false(\file_exists(
            \dirname(__DIR__) . '/Fixture/laravel/tests/Testo/Unit/LegacyExampleTest.php',
        ));
    }

    public function testPhpUnitMigrationCommandWritesTargetAndKeepsSourceByDefault(): void
    {
        $root = \dirname(__DIR__) . '/Fixture/laravel/tests';
        $source = $root . '/Unit/LegacyExampleTest.php';
        $target = $root . '/Testo/Unit/LegacyExampleTest.php';

        try {
            $this->artisan('laratesto:migrate-phpunit', [
                'source' => 'tests/Unit/LegacyExampleTest.php',
            ])->assertSuccessful();

            Assert::true(\is_file($source));
            Assert::true(\is_file($target));
            $contents = \file_get_contents($target);
            Assert::string($contents);
            Assert::string($contents)->contains('namespace Tests\Testo\Unit;');
            Assert::string($contents)->contains("Assert::same('expected', 'expected');");
        } finally {
            \is_file($target) && \unlink($target);
        }
    }

    public function testPhpUnitMigrationCommandRemovesSourceOnlyAfterVerifiedWrite(): void
    {
        $root = \dirname(__DIR__) . '/Fixture/laravel/tests';
        $template = $root . '/Unit/LegacyExampleTest.php';
        $source = $root . '/Unit/DisposableLegacyTest.php';
        $target = $root . '/Testo/Unit/DisposableLegacyTest.php';
        $contents = \file_get_contents($template);
        Assert::string($contents);
        Assert::true(\file_put_contents(
            $source,
            \str_replace('LegacyExampleTest', 'DisposableLegacyTest', $contents),
        ) !== false);

        try {
            $this->artisan('laratesto:migrate-phpunit', [
                'source' => 'tests/Unit/DisposableLegacyTest.php',
                '--remove-source' => true,
            ])->assertSuccessful();

            Assert::false(\file_exists($source));
            Assert::true(\is_file($target));
            $migrated = \file_get_contents($target);
            Assert::string($migrated);
            Assert::string($migrated)->contains('final class DisposableLegacyTest');
        } finally {
            \is_file($source) && \unlink($source);
            \is_file($target) && \unlink($target);
        }
    }

    private function fixturePath(): string
    {
        return \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'Fixture'
            . \DIRECTORY_SEPARATOR . 'laravel';
    }

    private function runnerBinaryPath(string $name): string
    {
        return $this->fixturePath() . \DIRECTORY_SEPARATOR . 'vendor'
            . \DIRECTORY_SEPARATOR . 'bin' . \DIRECTORY_SEPARATOR . $name;
    }

    /** @param list<string> $names @return list<string> */
    private function createRunnerBinaries(array $names): array
    {
        $directory = \dirname($this->runnerBinaryPath('testo'));
        if (!\is_dir($directory) && !\mkdir($directory, 0777, true) && !\is_dir($directory)) {
            throw new \RuntimeException("Unable to create {$directory}.");
        }

        $created = [];
        foreach ($names as $name) {
            $path = "{$directory}/{$name}";
            if (\is_file($path)) {
                continue;
            }
            if (\file_put_contents($path, '<?php') === false) {
                throw new \RuntimeException("Unable to create {$path}.");
            }
            $created[] = $path;
        }

        return $created;
    }

    /** @param list<string> $paths */
    private function removeRunnerBinaries(array $paths): void
    {
        foreach ($paths as $path) {
            \is_file($path) && \unlink($path);
        }

        $directory = \dirname($this->runnerBinaryPath('testo'));
        if (\is_dir($directory) && \count(\scandir($directory) ?: []) === 2) {
            \rmdir($directory);
            $vendor = \dirname($directory);
            \is_dir($vendor) && \count(\scandir($vendor) ?: []) === 2 && \rmdir($vendor);
        }
    }
}
