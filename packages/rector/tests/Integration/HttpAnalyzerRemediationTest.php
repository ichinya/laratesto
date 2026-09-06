<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Testo\Assert;
use Testo\Test;

/**
 * The dedicated HTTP-lane gate (PR #8 Sept 6 remediation): a corpus of real
 * Laravel tests is migrated by the real Rector binary with the public FULL set,
 * the migrated files must pass `php -l`, the second pass must be byte-identical,
 * and every remediation finding must show exactly the promised conversion or
 * residual at the public boundary.
 */
final class HttpAnalyzerRemediationTest
{
    #[Test]
    public function parentAssertCallsAreMarkedWhileLifecycleAndProjectParentsStaySupported(): void
    {
        $rootDir = \dirname(__DIR__, 4);
        $tmpDir = \sys_get_temp_dir() . '/laratesto-http-analyzer-' . \bin2hex(\random_bytes(8));

        Assert::true(\mkdir($tmpDir . '/corpus', 0777, true) || \is_dir($tmpDir . '/corpus'));

        try {
            \file_put_contents($tmpDir . '/corpus/ParentAssertProbeTest.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Pr8Http;

                use Illuminate\Foundation\Testing\TestCase;

                final class ParentAssertProbeTest extends TestCase
                {
                    protected function setUp(): void
                    {
                        parent::setUp();
                    }

                    public function test_parent_assert(): void
                    {
                        parent::assertTrue(true);
                    }
                }
                PHP);

            \file_put_contents($tmpDir . '/corpus/ParentLifecycleProbeTest.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Pr8Http;

                use Illuminate\Foundation\Testing\TestCase;

                final class ParentLifecycleProbeTest extends TestCase
                {
                    protected function setUp(): void
                    {
                        parent::setUp();
                    }

                    protected function tearDown(): void
                    {
                        parent::tearDown();
                    }

                    public function test_parent_helpers(): void
                    {
                        parent::artisan('cache:clear', []);
                    }
                }
                PHP);

            \file_put_contents($tmpDir . '/corpus/ParentProjectBaseProbeTest.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Pr8Http;

                use Illuminate\Foundation\Testing\TestCase;

                abstract class ProjectCase extends TestCase
                {
                    protected function assertTruth(bool $value): void
                    {
                    }
                }

                final class ParentProjectProbeTest extends ProjectCase
                {
                    public function test_project_parent(): void
                    {
                        parent::assertTruth(true);
                    }
                }
                PHP);

            // The isolated outside-hook regression: parent::setUp() inside a
            // non-lifecycle method is NOT rewritten by the base rule and must
            // fail closed instead of silently surviving on the converted base.
            \file_put_contents($tmpDir . '/corpus/ParentOutsideHookProbeTest.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Pr8Http;

                use Illuminate\Foundation\Testing\TestCase;

                final class ParentOutsideHookProbeTest extends TestCase
                {
                    public function testManualSetup(): void
                    {
                        parent::setUp();
                    }
                }
                PHP);

            foreach ([
                'NestedHook' => 'protected function setUp(): void { if (true) { parent::setUp(); } }',
                'ClosureHook' => 'protected function setUp(): void { $hook = function (): void { parent::setUp(); }; $hook(); }',
                'CaseHook' => 'protected function SETUP(): void { parent::setUp(); }',
                'MismatchedHook' => 'protected function tearDown(): void { parent::setUp(); }',
            ] as $name => $method) {
                \file_put_contents($tmpDir . '/corpus/' . $name . '.php', '<?php namespace Pr8Http; final class ' . $name
                    . ' extends \\Illuminate\\Foundation\\Testing\\TestCase { ' . $method . ' }');
            }
            foreach (\glob($tmpDir . '/corpus/*.php') ?: [] as $file) {
                $this->assertValidPhp($file);
            }

            $this->runRector($rootDir, $tmpDir, [$tmpDir . '/corpus']);

            $marked = (string) \file_get_contents($tmpDir . '/corpus/ParentAssertProbeTest.php');

            // The parent assert call survives the upstream rewrite (it only covers
            // $this/self/static), so it must be residual-marked, not silent.
            Assert::string($marked)->contains(
                'laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE',
                'The surviving parent::assertTrue() must be residual-marked.',
            );
            Assert::string($marked)->contains('parent::assertTrue() lands on the converted Laratesto base');
            Assert::string($marked)->contains('parent::assertTrue(true);', 'The original call must be preserved for manual migration.');

            // The lifecycle counterpart converts: setUp()/tearDown() rename to
            // their Laratesto hooks and the framework parent call is dropped,
            // while parent::artisan() survives on the converted base's own
            // helper surface - all marker-free.
            $lifecycle = (string) \file_get_contents($tmpDir . '/corpus/ParentLifecycleProbeTest.php');
            Assert::string($lifecycle)->contains('protected function setUpLaravel(): void');
            Assert::string($lifecycle)->notContains('parent::setUp()');
            Assert::string($lifecycle)->contains("parent::artisan('cache:clear', []);");
            Assert::string($lifecycle)->notContains('laratesto-residual', 'Supported parent:: usage must not produce residuals.');

            // The provable project parent control: ProjectCase converts too, its
            // own assertTruth() survives, so parent::assertTruth() stays clean.
            $project = (string) \file_get_contents($tmpDir . '/corpus/ParentProjectBaseProbeTest.php');
            Assert::string($project)->contains('parent::assertTruth(true);', 'The provable project-parent call must be preserved.');
            Assert::string($project)->notContains('laratesto-residual', 'A project-declared parent method must not be residual-marked.');

            // The outside-hook regression: parent::setUp() outside any lifecycle
            // override is marked, the original call preserved, the file valid.
            $outsideHook = (string) \file_get_contents($tmpDir . '/corpus/ParentOutsideHookProbeTest.php');
            Assert::string($outsideHook)->contains('laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE');
            Assert::string($outsideHook)->contains('parent::setUp() lands on the converted Laratesto base');
            Assert::string($outsideHook)->contains('parent::setUp();');

            foreach (['NestedHook', 'ClosureHook', 'CaseHook', 'MismatchedHook'] as $name) {
                $output = (string) \file_get_contents($tmpDir . '/corpus/' . $name . '.php');
                Assert::string($output)->contains('HTTP_UNSUPPORTED_SIGNATURE');
                Assert::string($output)->contains('parent::setUp();');
            }
            foreach (\glob($tmpDir . '/corpus/*.php') ?: [] as $file) {
                $this->assertValidPhp($file);
            }

            // Byte-identical second pass.
            $hashes = $this->snapshotDirectory($tmpDir . '/corpus');
            $this->runRector($rootDir, $tmpDir, [$tmpDir . '/corpus']);
            Assert::same($hashes, $this->snapshotDirectory($tmpDir . '/corpus'), 'The second pass must be byte-identical.');
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    private function assertValidPhp(string $path): void
    {
        \exec('php -l ' . \escapeshellarg($path) . ' 2>&1', $out, $code);

        Assert::same(0, $code, "php -l failed for {$path}: " . \implode("\n", $out));
    }

    private function runRector(string $rootDir, string $tmpDir, array $paths): void
    {
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';
        Assert::true(\is_file($rectorBin), 'rector binary not found at ' . $rectorBin);

        $cacheExport = \var_export($tmpDir . '/rector-cache', true);
        $pathsExport = \var_export($paths, true);
        $setExport = \var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
        \file_put_contents($tmpDir . '/rector.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Rector\Config\RectorConfig;

            return RectorConfig::configure()
                ->withCache(cacheDirectory: {$cacheExport})
                ->withPaths({$pathsExport})
                ->withSets([{$setExport}]);
            PHP);

        $process = \proc_open(
            [\PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'],
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
     * @return array<string, string>
     */
    private function snapshotDirectory(string $directory): array
    {
        $hashes = [];

        foreach (\glob($directory . '/*.php') ?: [] as $file) {
            $hashes[$file] = (string) \file_get_contents($file);
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
