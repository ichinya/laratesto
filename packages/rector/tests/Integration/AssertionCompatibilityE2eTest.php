<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Laratesto\Migration\PhpUnitToTestoMigrator;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class AssertionCompatibilityE2eTest
{
    #[Test]
    public function publicSetAndLegacyConverterPreservePassingAndFailingControls(): void
    {
        $root = \dirname(__DIR__, 4);
        $tmp = \sys_get_temp_dir() . '/laratesto-assertion-parity-' . \bin2hex(\random_bytes(6));
        \mkdir($tmp . '/corpus', 0777, true);
        try {
            $file = $tmp . '/corpus/AssertionParityTest.php';
            $source = (string) \file_get_contents($root . '/tests/Fixture/issue10/AssertionParityTest.php');
            \file_put_contents($file, $source);
            $strictSource = (string) \file_get_contents($root . '/tests/Fixture/issue10/StrictStringParityTest.php');
            $strictFile = $tmp . '/corpus/StrictStringParityTest.php';
            \file_put_contents($strictFile, $strictSource);
            $paths = \var_export([$tmp . '/corpus'], true);
            $set = \var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            $cache = \var_export($tmp . '/cache', true);
            \file_put_contents($tmp . '/rector.php', "<?php return static function (\\Rector\\Config\\RectorConfig \$config): void { \$config->paths({$paths}); \$config->import({$set}); \$config->cacheDirectory({$cache}); \$config->disableParallel(); };");
            $args = [\PHP_BINARY, $root . '/vendor/bin/rector', 'process', '--config', $tmp . '/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'];
            $this->run($args, $root, 0);
            $first = \file_get_contents($file);
            $strictFirst = \file_get_contents($strictFile);
            $this->run($args, $root, 0);
            Assert::same($first, \file_get_contents($file), 'Second apply must preserve every byte.');
            Assert::same($strictFirst, \file_get_contents($strictFile), 'Second apply must preserve strict callers.');

            $location = \var_export([$tmp . '/corpus'], true);
            \file_put_contents($tmp . '/testo.php', "<?php return new \\Testo\\Application\\Config\\ApplicationConfig(suites: [new \\Testo\\Application\\Config\\SuiteConfig(name: 'Parity', location: {$location}, plugins: [new \\Testo\\Convention\\NamingConventionPlugin()])]);");
            $testo = [\PHP_BINARY, $root . '/vendor/bin/testo', 'run', '--config', $tmp . '/testo.php', '--no-ansi', '--log-json=' . $tmp . '/result.json'];
            $this->run($testo, $root, 1);
            $this->assertParity($tmp . '/result.json');

            $legacy = (new PhpUnitToTestoMigrator())->migrate($source);
            Assert::true($legacy->successful(), \implode('; ', $legacy->errors));
            \file_put_contents($file, $legacy->code);
            $strictLegacy = (new PhpUnitToTestoMigrator())->migrate($strictSource);
            Assert::true($strictLegacy->successful(), \implode('; ', $strictLegacy->errors));
            \file_put_contents($strictFile, $strictLegacy->code);
            $this->run($testo, $root, 1);
            $this->assertParity($tmp . '/result.json');
        } finally {
            (new Filesystem())->deleteDirectory($tmp);
        }
    }

    private function assertParity(string $file): void
    {
        $report = \json_decode((string) \file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        Assert::same(['total' => 13, 'passed' => 9, 'failed' => 4], $report['totals']);
        $failed = \array_map(static fn (array $failure): string => \explode('::', $failure['test'])[1], $report['failures']);
        \sort($failed);
        Assert::same(['testNegativeEmptyMessage', 'testNegativeNoException', 'testNegativeWrongMessage', 'testNegativeWrongType'], $failed);
    }

    /** @param list<string> $args */
    private function run(array $args, string $cwd, int $expectedExit): void
    {
        $process = new Process($args, $cwd, timeout: 120);
        $exit = $process->run();
        Assert::same($expectedExit, $exit, $exit === $expectedExit ? '' : $process->getOutput() . $process->getErrorOutput());
    }
}
