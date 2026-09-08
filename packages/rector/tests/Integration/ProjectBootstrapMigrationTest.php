<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class ProjectBootstrapMigrationTest
{
    #[Test]
    public function customBootstrapFakerAndNestedClassRunAfterAutomaticMigration(): void
    {
        $root = dirname(__DIR__, 4);
        $temporary = sys_get_temp_dir().'/laratesto-project-bootstrap-'.bin2hex(random_bytes(6));
        mkdir($temporary.'/corpus', 0777, true);
        $file = $temporary.'/corpus/ProjectBootstrapTest.php';
        copy($root.'/tests/Fixture/issue10/ProjectBootstrapTest.php', $file);
        $environment = ['LARATESTO_ISSUE10_APP' => $root.'/tests/Fixture/laravel', 'APP_ENV' => 'testing'];
        try {
            $bootstrap = $temporary.'/bootstrap.php';
            file_put_contents($bootstrap, '<?php require_once '.var_export($root.'/vendor/autoload.php', true).'; require '.var_export($root.'/tests/Fixture/issue10/register-response-macro.php', true).';');
            $this->run([PHP_BINARY, $root.'/vendor/bin/phpunit', '--no-configuration', '--bootstrap', $bootstrap, '--log-junit', $temporary.'/phpunit.xml', $file], $root, $environment, 1);
            $sourceReport = simplexml_load_file($temporary.'/phpunit.xml');
            Assert::same(8, count($sourceReport->xpath('//testcase')));
            Assert::same(2, count($sourceReport->xpath('//testcase/failure')));
            Assert::same(0, count($sourceReport->xpath('//testcase/error')));
            $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            $cache = var_export($temporary.'/cache', true);
            file_put_contents($temporary.'/rector.php', '<?php return static function (\\Rector\\Config\\RectorConfig $config): void { $config->import('.$set.'); $config->cacheDirectory('.$cache.'); $config->disableParallel(); };');
            $command = [PHP_BINARY, $root.'/vendor/bin/rector', 'process', $temporary.'/corpus', '--config', $temporary.'/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'];
            $this->run($command, $root, $environment);
            $first = file_get_contents($file);
            Assert::false(str_contains($first, 'laratesto-residual'), implode("\n", preg_grep('/laratesto-residual/', explode("\n", $first))));
            Assert::string($first)->contains('extends \\Laratesto\\Testing\\LaravelTestCase');
            $this->run([PHP_BINARY, '-l', $file], $root, $environment);
            $this->run($command, $root, $environment);
            Assert::same($first, file_get_contents($file), 'A second migration must preserve every byte.');
            $location = var_export([$temporary.'/corpus'], true);
            $app = var_export($root.'/tests/Fixture/laravel', true);
            file_put_contents($temporary.'/testo.php', '<?php require '.var_export($bootstrap, true).'; return new \\Testo\\Application\\Config\\ApplicationConfig(suites: [new \\Testo\\Application\\Config\\SuiteConfig(name: "Migration", location: '.$location.', plugins: [new \\Testo\\Convention\\NamingConventionPlugin(), new \\Laratesto\\LaravelPlugin(new \\Laratesto\\Config\\LaravelConfig(basePath: '.$app.'))])]);');
            $this->run([PHP_BINARY, $root.'/vendor/bin/testo', 'run', '--config', $temporary.'/testo.php', '--log-json='.$temporary.'/result.json'], $root, $environment, 1);
            $report = json_decode(file_get_contents($temporary.'/result.json'), true, flags: JSON_THROW_ON_ERROR);
            Assert::same(['total' => 8, 'passed' => 6, 'failed' => 2], $report['totals']);
            $failures = array_column($report['failures'], 'test');
            sort($failures);
            Assert::same(['Tests\ProjectBootstrapTest::testNegativeFacadeAssertionStillFails', 'Tests\ProjectBootstrapTest::testNegativeFrameworkAssertionStillFails'], $failures);
        } finally {
            (new Filesystem())->deleteDirectory($temporary);
        }
    }

    private function run(array $command, string $root, array $environment, int $expectedExit = 0): void
    {
        $process = new Process($command, $root, $environment, timeout: 120);
        $exit = $process->run();
        Assert::same($expectedExit, $exit, $exit === $expectedExit ? '' : $process->getOutput().$process->getErrorOutput());
    }
}
