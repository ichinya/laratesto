<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class FilesystemAssertionParityTest
{
    #[Test]
    public function fileAndDirectoryVerdictsSurviveMigrationAndRuntimeHelpers(): void
    {
        $root = dirname(__DIR__, 3);
        $tmp = sys_get_temp_dir() . '/laratesto-file-parity-' . bin2hex(random_bytes(8));
        mkdir($tmp . '/corpus', 0777, true);
        $source = <<<'PHP'
            <?php
            namespace Tests\Unit;
            final class FileProbe extends \PHPUnit\Framework\TestCase {
                public function testExistingFile(): void { $this->assertFileExists(__FILE__); }
                public function testExistingDirectory(): void { $this->assertFileExists(__DIR__); }
                public function testNamedDirectory(): void { $this->assertFileExists(filename: __DIR__, message: 'named directory'); }
                public function testAbsentFile(): void { $this->assertFileDoesNotExist(__DIR__ . '/absent'); }
                public function testDirectoryKind(): void { $this->assertDirectoryExists(__DIR__); }
                public function testNegativeMissingFile(): void { $this->assertFileExists(__DIR__ . '/absent', 'missing file control'); }
                public function testNegativeExistingDirectory(): void { $this->assertFileDoesNotExist(filename: __DIR__, message: 'existing directory control'); }
                public function testNegativeExistingFile(): void { $this->assertFileDoesNotExist(__FILE__, 'existing file control'); }
            }
            PHP;
        $file = $tmp . '/corpus/FileProbe.php';
        $run = static function (array $command, int $expected) use ($root): void {
            $process = new Process($command, $root, timeout: 120);
            $process->run();
            Assert::same($expected, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        };
        try {
            $location = var_export([$tmp . '/corpus'], true);
            $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            file_put_contents($tmp . '/rector.php', '<?php return \Rector\Config\RectorConfig::configure()->withPaths(' . $location . ')->withSets([' . $set . '])->withCache(cacheDirectory: ' . var_export($tmp . '/cache', true) . ')->withoutParallel();');
            file_put_contents($tmp . '/testo.php', '<?php return new \Testo\Application\Config\ApplicationConfig(suites: [new \Testo\Application\Config\SuiteConfig(name: "Files", location: ' . $location . ')]);');
            foreach (['rector', 'runtime'] as $mode) {
                file_put_contents($file, $source);
                if ($mode === 'rector') {
                    $args = [PHP_BINARY, $root . '/vendor/bin/rector', 'process', '--config', $tmp . '/rector.php', '--clear-cache', '--no-progress-bar', '--no-ansi'];
                    $before = file_get_contents($file);
                    $run([...$args, '--dry-run'], 2);
                    Assert::same($before, file_get_contents($file));
                    $run($args, 0);
                    $first = file_get_contents($file);
                    $run($args, 0);
                    Assert::same($first, file_get_contents($file));
                } else {
                    $direct = str_replace('extends \\PHPUnit\\Framework\\TestCase', '', $source);
                    $direct = str_replace('public function test', '#[\\Testo\\Test] public function test', $direct);
                    $direct = str_replace('$this->assert', '\\Laratesto\\Testing\\PhpUnitCompatibility::assert', $direct);
                    file_put_contents($file, $direct);
                }
                $run([PHP_BINARY, $root . '/vendor/bin/testo', 'run', '--config', $tmp . '/testo.php', '--log-json=' . $tmp . '/result.json', '--no-ansi'], 1);
                $report = json_decode(file_get_contents($tmp . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
                Assert::same(['total' => 8, 'assertions' => 8, 'passed' => 5, 'failed' => 3], $report['totals']);
                $failed = array_map(static fn(array $failure): string => explode('::', $failure['test'])[1], $report['failures']);
                sort($failed);
                Assert::same(['testNegativeExistingDirectory', 'testNegativeExistingFile', 'testNegativeMissingFile'], $failed);
                Assert::string(json_encode($report['failures']))->contains('existing directory control');
            }
        } finally {
            (new Filesystem())->deleteDirectory($tmp);
        }
    }
}
