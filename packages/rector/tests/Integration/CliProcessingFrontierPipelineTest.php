<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Rules\LaravelDatabaseTraitsRector;
use Laratesto\Rector\Tests\Support\FrontierPipeline;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class CliProcessingFrontierPipelineTest
{
    #[Test]
    public function selectedHierarchyConvertsForConfiguredAndCliPathsInEitherOrder(): void
    {
        foreach ([false, true] as $childFirst) {
            $files = self::corpus($childFirst);
            foreach ([null, [], array_keys($files)] as $cliPaths) {
                $output = FrontierPipeline::run($files, cliPaths: $cliPaths);
                self::assertConverted($output);
            }
        }
    }

    #[Test]
    public function cliSelectionOverridesBroaderConfiguredAndAutoloadPaths(): void
    {
        $files = self::corpus();
        foreach ([
            [['ZChild.php'], ['']], // Positional CLI must override the configured directory.
            [['ZChild.php'], []], // The base is visible only through autoload paths.
            [null, ['ZChild.php']], // Configured child-only control.
        ] as [$cliPaths, $configuredPaths]) {
            $output = FrontierPipeline::run($files, cliPaths: $cliPaths, configuredPaths: $configuredPaths, autoloadPaths: ['']);
            Assert::same($files['ABase.php'], $output['ABase.php']);
            Assert::string($output['ZChild.php'])->contains('is outside the processed paths');
            Assert::string($output['ZChild.php'])->notContains('#[\\Testo\\Test]');
        }
    }

    #[Test]
    public function cliSourcesStillRespectFileAndRuleSkips(): void
    {
        foreach ([
            ['ABase.php'],
            [LaravelBaseClassRector::class => ['*Base.php']],
            [LaravelDatabaseTraitsRector::class => ['ABase.php']],
        ] as $skip) {
            $files = self::corpus();
            $output = FrontierPipeline::run($files, $skip, cliPaths: [], autoloadPaths: ['']);
            Assert::string($output['ABase.php'])->contains('extends \\Illuminate\\Foundation\\Testing\\TestCase');
            if (! isset($skip[LaravelBaseClassRector::class])) {
                Assert::string($output['ABase.php'])->contains('use \\Illuminate\\Foundation\\Testing\\RefreshDatabase;');
            }
            Assert::string($output['ZChild.php'])->contains('skip configuration');
            Assert::string($output['ZChild.php'])->notContains('#[\\Testo\\Test]');
        }
    }

    #[Test]
    public function generatedCliHierarchyRunsThroughTestoWithInheritedDatabaseStrategy(): void
    {
        $output = FrontierPipeline::run(self::corpus(), cliPaths: []);
        self::assertConverted($output);
        $root = dirname(__DIR__, 4);
        $tmp = sys_get_temp_dir() . '/laratesto-cli-runtime-' . bin2hex(random_bytes(8));
        foreach (['corpus', 'app/bootstrap/cache', 'app/config', 'app/database/migrations', 'app/storage/logs'] as $directory) {
            Assert::true(mkdir($tmp . '/' . $directory, 0777, true));
        }
        foreach ($output as $name => $bytes) {
            file_put_contents($tmp . '/corpus/' . $name, $bytes);
        }
        file_put_contents($tmp . '/app/bootstrap/app.php', <<<'PHP'
            <?php
            return \Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))->create();
            PHP);
        file_put_contents($tmp . '/app/config/database.php', <<<'PHP'
            <?php
            return ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']], 'migrations' => ['table' => 'migrations']];
            PHP);
        $corpus = var_export($tmp . '/corpus', true);
        $app = var_export($tmp . '/app', true);
        file_put_contents($tmp . '/testo.php', <<<PHP
            <?php
            require_once {$corpus} . '/ABase.php';
            require_once {$corpus} . '/ZChild.php';
            return new \Testo\Application\Config\ApplicationConfig(suites: [
                new \Testo\Application\Config\SuiteConfig(name: 'GeneratedCli', location: [{$corpus}], plugins: [
                    new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(basePath: {$app})),
                ]),
            ]);
            PHP);
        foreach (['app/bootstrap/app.php', 'app/config/database.php', 'testo.php'] as $file) {
            self::process([PHP_BINARY, '-l', $tmp . '/' . $file], $root);
        }
        $result = self::process([PHP_BINARY, $root . '/vendor/bin/testo', 'run', '--config', $tmp . '/testo.php', '--no-ansi', '--log-json', $tmp . '/runtime.json'], $root);
        Assert::string($result)->contains('testInheritedTransaction');
        $report = json_decode((string) file_get_contents($tmp . '/runtime.json'), true, flags: JSON_THROW_ON_ERROR);
        Assert::same($report['totals']['total'], 1);
        Assert::same($report['totals']['passed'], 1);
        Assert::string($result)->contains('2 assertions');
    }

    /** @return array<string, string> */
    private static function corpus(bool $childFirst = false): array
    {
        $base = <<<'PHP'
            <?php
            namespace Tests;
            abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
            { use \Illuminate\Foundation\Testing\RefreshDatabase; }
            PHP;
        $child = <<<'PHP'
            <?php
            namespace Tests;
            final class ChildTest extends TestCase
            {
                protected bool $seed = false;
                public function testInheritedTransaction(): void
                {
                    $this->assertSame(1, \Illuminate\Support\Facades\DB::connection()->transactionLevel());
                    $this->assertSame(42, (int) \Illuminate\Support\Facades\DB::selectOne('select 6 * 7 as value')->value);
                }
            }
            PHP;

        return $childFirst ? ['AChild.php' => $child, 'ZBase.php' => $base] : ['ABase.php' => $base, 'ZChild.php' => $child];
    }

    /** @param array<string, string> $output */
    private static function assertConverted(array $output): void
    {
        $combined = implode("\n", $output);
        Assert::string($combined)->contains('extends \\Laratesto\\Testing\\LaravelTestCase');
        Assert::same(1, substr_count($combined, '#[\\Laratesto\\Attribute\\RefreshDatabase]'));
        Assert::same(1, substr_count($combined, '#[\\Testo\\Test]'));
        Assert::string($combined)->notContains('laratesto-residual');
        Assert::string($combined)->notContains('use \\Illuminate\\Foundation\\Testing\\RefreshDatabase;');
    }

    /** @param list<string> $command */
    private static function process(array $command, string $root): string
    {
        $process = new Process($command, $root, timeout: 180.0);
        $process->run();
        Assert::same(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        return $process->getOutput();
    }
}
