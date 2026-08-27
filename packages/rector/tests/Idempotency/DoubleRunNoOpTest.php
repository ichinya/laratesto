<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Idempotency;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Testo\Assert;
use Testo\Test;

/**
 * Ticket 01 acceptance (story: issue #7, п. 6): running our set over the same input
 * twice must produce changes only ONCE — the second run leaves files untouched.
 *
 * Runs the real `rector` binary against a throwaway copy of a representative Laravel
 * PHPUnit fixture, hashing the corpus between runs instead of parsing console output.
 */
final class DoubleRunNoOpTest
{
    private const CORPUS_SAMPLE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class UsersListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_users_list_renders(): void
    {
        $response = $this->get('/users');

        $response->assertStatus(200);
    }
}
PHP;

    #[Test]
    public function secondRunLeavesCorpusUntouched(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';
        Assert::true(is_file($rectorBin), 'rector binary not found at ' . $rectorBin);

        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-double-run-' . getmypid();
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        try {
            file_put_contents($tmpDir . '/corpus/UsersListTest.php', self::CORPUS_SAMPLE);

            // Config written inside the tmp tree so relative paths always point at the copy.
            $withPaths = var_export($tmpDir . '/corpus', true);
            $sets = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            file_put_contents(
                $tmpDir . '/rector.php',
                <<<PHP
<?php

declare(strict_types=1);

use Rector\\Config\\RectorConfig;

return RectorConfig::configure()
    ->withPaths([{$withPaths}])
    ->withSets([{$sets}]);
PHP,
            );

            $runOnce = static function () use ($rectorBin, $tmpDir): string {
                $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                // argv-array invocation bypasses the shell: no escaping needed on any platform.
                $process = proc_open(
                    [PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi'],
                    $descriptors,
                    $pipes,
                    dirname(__DIR__, 4),
                );
                Assert::true(is_resource($process), 'proc_open failed');
                $stdout = (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);

                Assert::same(0, $exitCode, "rector failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");

                return $stdout . $stderr;
            };

            // Run 1: allowed to modify. Run 2: strictly forbidden to touch anything.
            $runOnce();
            $corpusFile = $tmpDir . '/corpus/UsersListTest.php';
            $hashAfterFirstRun = md5_file($corpusFile);
            $sourceAfterFirstRun = file_get_contents($corpusFile);

            Assert::true(is_string($hashAfterFirstRun) && is_string($sourceAfterFirstRun));

            $runOnce();

            Assert::same(
                $hashAfterFirstRun,
                md5_file($corpusFile),
                "Second run modified an already-migrated file. After run 1:\n" . $sourceAfterFirstRun,
            );
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    private static function recursiveRemove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::recursiveRemove($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
