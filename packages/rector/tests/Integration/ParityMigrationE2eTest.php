<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Testo\Assert;
use Testo\Test;

/**
 * The real migration gate (PR #8 fix plan, stage 8 / parity scenarios): the fixture
 * corpus is migrated by the real Rector binary, the run is idempotent byte-for-byte,
 * unsupported constructs stay behind with their residual markers, and the MIGRATED
 * suite then runs green under a real `testo run` — without PHPUnit, with the custom
 * project base behavior intact.
 */
final class ParityMigrationE2eTest
{
    #[Test]
    public function migratedParityCorpusRunsGreenUnderTesto(): void
    {
        $rootDir = \dirname(__DIR__, 4);
        $tmpDir = \sys_get_temp_dir() . '/laratesto-parity-e2e-' . \getmypid();

        Assert::true(\mkdir($tmpDir, 0777, true) || \is_dir($tmpDir));

        try {
            $corpus = $tmpDir . '/corpus';
            $unsupported = $tmpDir . '/unsupported';

            Assert::true(\mkdir($corpus, 0777, true) || \is_dir($corpus));
            Assert::true(\mkdir($unsupported, 0777, true) || \is_dir($unsupported));

            foreach (['TestCase.php', 'LifecycleCountersTest.php', 'DatabaseStrategiesTest.php', 'TruncationSelectionTest.php', 'HttpAndArtisanTest.php'] as $file) {
                Assert::true(\copy($rootDir . '/tests/Fixture/parity/supported/' . $file, $corpus . '/' . $file), 'Missing supported fixture: ' . $file);
            }

            foreach (\glob($rootDir . '/tests/Fixture/parity/unsupported/*.php') ?: [] as $file) {
                Assert::true(\copy($file, $unsupported . '/' . \basename($file)), 'Missing unsupported fixture: ' . $file);
            }

            $this->runRector($rootDir, $tmpDir, [$corpus, $unsupported]);

            // Project base converted exactly once, custom behavior kept.
            $base = (string) \file_get_contents($corpus . '/TestCase.php');
            Assert::string($base)->contains('extends \Laratesto\Testing\LaravelTestCase');
            Assert::string($base)->contains('setUpLaravel');
            Assert::string($base)->contains("bind('parity.clock'");
            Assert::string($base)->notContains('extends FoundationTestCase');

            // Descendants keep the project base and gain only the Testo surface.
            $counters = (string) \file_get_contents($corpus . '/LifecycleCountersTest.php');
            Assert::string($counters)->contains('extends TestCase');
            Assert::string($counters)->notContains('Laratesto\Testing\LaravelTestCase');
            Assert::string($counters)->contains('function setUpLaravel(): void');
            Assert::string($counters)->contains('#[\Testo\Test]');
            Assert::string($counters)->notContains('PHPUnit');

            // Database strategies became attributes: the plain RefreshDatabase class,
            // the wrapped-transactions class and the literal two-connection truncation.
            $strategies = (string) \file_get_contents($corpus . '/DatabaseStrategiesTest.php');
            Assert::string($strategies)->contains('#[\Laratesto\Attribute\RefreshDatabase]');
            Assert::string($strategies)->contains('#[\Laratesto\Attribute\DatabaseTransactions]');
            Assert::string($strategies)->notContains('use Illuminate\Foundation\Testing\DatabaseTransactions;');
            Assert::string($strategies)->notContains('use RefreshDatabase;');

            $truncation = (string) \file_get_contents($corpus . '/TruncationSelectionTest.php');
            Assert::string($truncation)->contains('#[\Laratesto\Attribute\DatabaseTruncation(connections: [\'sqlite\', \'secondary\'], tables: [\'things\'])]');

            // Unsupported constructs stay put with exactly their residual markers.
            $dynamic = (string) \file_get_contents($unsupported . '/UnsupportedCorpus.php');
            Assert::string($dynamic)->contains('laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION');
            Assert::string($dynamic)->contains('laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE');
            Assert::string($dynamic)->contains('laratesto-residual(code=LARAVEL_FAKE_UNSUPPORTED');
            Assert::string($dynamic)->contains('laratesto-residual(code=ARTISAN_INTERACTION_UNSUPPORTED');
            Assert::string($dynamic)->contains('expectsQuestion');

            // Idempotency: a second apply must not move a single byte.
            $hashes = $this->hashDirectory($corpus, $unsupported);
            $this->runRector($rootDir, $tmpDir, [$corpus, $unsupported]);
            Assert::same($hashes, $this->hashDirectory($corpus, $unsupported), 'A second apply must be byte-for-byte idempotent.');

            // The real gate: the migrated corpus runs green under a real testo run.
            $config = $tmpDir . '/testo.php';
            $fixtureApp = $rootDir . '/tests/Fixture/laravel';
            $corpusExport = \var_export($corpus, true);
            $appExport = \var_export($fixtureApp, true);
            $baseFileExport = \var_export($corpus . '/TestCase.php', true);
            $corpusGlobExport = \var_export($corpus . '/*Test.php', true);

            \file_put_contents($config, <<<PHP
                <?php

                declare(strict_types=1);

                use Laratesto\Config\LaravelConfig;
                use Laratesto\LaravelPlugin;
                use Testo\Application\Config\ApplicationConfig;
                use Testo\Application\Config\SuiteConfig;
                use Testo\Convention\NamingConventionPlugin;

                // Preload the migrated corpus (project base first) so the tokenizer's
                // reflection can link every descendant to its parent class.
                require_once {$baseFileExport};
                foreach (\glob({$corpusGlobExport}) as \$corpusFile) {
                    require_once \$corpusFile;
                }

                return new ApplicationConfig(
                    suites: [
                        new SuiteConfig(
                            name: 'Parity',
                            location: [{$corpusExport}],
                            plugins: [
                                new NamingConventionPlugin(),
                                new LaravelPlugin(
                                    new LaravelConfig(
                                        basePath: {$appExport},
                                        config: ['app.name' => 'Parity E2E'],
                                    ),
                                ),
                            ],
                        ),
                    ],
                );
                PHP);

            $testoBin = $rootDir . '/vendor/testo/bridge-symfony-console/bin/testo';
            Assert::true(\is_file($testoBin), 'testo binary not found at ' . $testoBin);

            $process = \proc_open(
                [\PHP_BINARY, $testoBin, 'run', '--config', $config, '--no-ansi'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $rootDir,
            );
            Assert::true(\is_resource($process), 'proc_open failed for the testo run');
            \fclose($pipes[0]);
            $stdout = (string) \stream_get_contents($pipes[1]);
            \fclose($pipes[1]);
            $stderr = (string) \stream_get_contents($pipes[2]);
            \fclose($pipes[2]);
            $exitCode = \proc_close($process);

            Assert::same(
                0,
                $exitCode,
                "The migrated parity corpus must run green under testo.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
            );
            Assert::string($stdout)->contains('LifecycleCountersTest');
            Assert::string($stdout)->notContains('FAILED');
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    /**
     * Applies the public set over the given paths with the real Rector binary.
     *
     * @param list<string> $paths
     */
    private function runRector(string $rootDir, string $tmpDir, array $paths): void
    {
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';
        Assert::true(\is_file($rectorBin), 'rector binary not found at ' . $rectorBin);

        $pathsExport = \var_export($paths, true);
        $setExport = \var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
        \file_put_contents($tmpDir . '/rector.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Rector\Config\RectorConfig;

            return RectorConfig::configure()
                ->withPaths({$pathsExport})
                ->withSets([{$setExport}]);
            PHP);

        $process = \proc_open(
            [\PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $rootDir,
        );
        Assert::true(\is_resource($process), 'proc_open failed');
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $exitCode = \proc_close($process);

        Assert::same(0, $exitCode, "rector failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
    }

    /**
     * @param list<string>|string $directories
     * @return array<string, string>
     */
    private function hashDirectory(string ...$directories): array
    {
        $hashes = [];

        foreach ($directories as $directory) {
            foreach (\glob($directory . '/*.php') ?: [] as $file) {
                $hashes[$file] = (string) \md5_file($file);
            }
        }

        \ksort($hashes);

        return $hashes;
    }

    private static function recursiveRemove(string $dir): void
    {
        if (! \is_dir($dir)) {
            return;
        }

        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            \is_dir($path) ? self::recursiveRemove($path) : @\unlink($path);
        }

        @\rmdir($dir);
    }
}
