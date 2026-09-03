<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Support;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;

/**
 * Shared harness for the byte-idempotency tests: runs the real `rector` binary with
 * the full public set over a throwaway corpus copy, twice. argv-array invocation
 * bypasses the shell: no escaping needed on any platform.
 */
final class RectorRun
{
    /**
     * @param array<string, string> $files Corpus file name => contents, written into a
     *        throwaway corpus directory.
     * @return array<string, string> Corpus file name => contents after the runs. The
     *         second run left every file byte-identical to the first.
     */
    public static function twiceWithByteIdenticalSecondRun(array $files): array
    {
        $rootDir = \dirname(__DIR__, 4);

        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';

        $tmpDir = \sys_get_temp_dir() . '/laratesto-rector-run-' . \getmypid() . '-' . \md5(\implode('|', \array_keys($files)));
        self::recursiveRemove($tmpDir);

        $corpusDir = $tmpDir . '/corpus';
        Assert::true(\mkdir($corpusDir, 0777, true) || \is_dir($corpusDir));

        try {
            foreach ($files as $name => $contents) {
                \file_put_contents($corpusDir . '/' . $name, $contents);
            }

            // Config written inside the tmp tree so relative paths always point at the copy.
            $withPaths = \var_export($corpusDir, true);
            $sets = \var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            \file_put_contents(
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
            // Bounded Symfony Process invocation: both pipes are drained
            // concurrently, so a huge stderr trace cannot deadlock the harness,
            // and a hung binary dies on the timeout instead of stalling the suite.
            $runOnce = static function () use ($rectorBin, $tmpDir, $rootDir): void {
                $process = new Process(
                    [PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi'],
                    $rootDir,
                    timeout: 300.0,
                );
                $process->run();

                Assert::same(0, $process->getExitCode(), \sprintf(
                    "rector failed (exit %d).\nSTDOUT:\n%s\nSTDERR:\n%s",
                    (int) $process->getExitCode(),
                    $process->getOutput(),
                    $process->getErrorOutput(),
                ));
            };

            // Run 1: allowed to modify — and must actually change at least one
            // input file, or the fixture corpus is stale and the idempotency
            // proof below would pass vacuously. Run 2: strictly forbidden to
            // touch anything.
            $runOnce();

            $snapshot = self::corpus($corpusDir);
            Assert::true($snapshot !== [], 'The corpus disappeared after the first run.');
            Assert::true($snapshot != $files, 'The first Rector run must change at least one input file.');

            $runOnce();

            Assert::same(self::corpus($corpusDir), $snapshot, 'Second run modified an already-migrated file.');

            return $snapshot;
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    /**
     * @return array<string, string> Corpus file name => contents.
     */
    private static function corpus(string $dir): array
    {
        $contents = [];

        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                foreach (self::corpus($path) as $nestedName => $nestedContents) {
                    $contents[$entry . '/' . $nestedName] = $nestedContents;
                }

                continue;
            }

            $fileContents = \file_get_contents($path);
            Assert::true(\is_string($fileContents), 'Unable to read corpus file ' . $path);
            $contents[$entry] = $fileContents;
        }

        return $contents;
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
