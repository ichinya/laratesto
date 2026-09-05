<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * The confirmed LazilyRefreshDatabase migration gap, end to end over the real public
 * set: the lazy strategy has no Testo counterpart and is deliberately NOT converted
 * (mapping it to the eager RefreshDatabase attribute would change refresh timing), so
 * the migration must leave the source trait in place for the manual follow-up AND make
 * the gap visible through the canonical residual marker — in an LF and a CRLF corpus
 * alike — while an ordinary RefreshDatabase class still migrates clean without any
 * residual. Content is asserted after newline normalization (line-ending preservation
 * is the printer's business, not this contract); the idempotency proof compares the
 * second run raw byte-for-byte.
 */
final class LazyDatabaseStrategyPipelineTest
{
    private const LAZY_CORPUS = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests;

        use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

        abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase {}

        final class LazyTest extends TestCase
        {
            use LazilyRefreshDatabase;

            public function testDatabase(): void
            {
                $this->assertDatabaseHas('things', ['name' => 'seeded']);
            }
        }
        PHP;

    private const EAGER_CONTROL_CORPUS = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests;

        use Illuminate\Foundation\Testing\RefreshDatabase;

        abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase {}

        final class EagerControlTest extends TestCase
        {
            use RefreshDatabase;

            public function testDatabase(): void
            {
                $this->assertDatabaseHas('things', ['name' => 'seeded']);
            }
        }
        PHP;

    #[Test]
    public function lazyTraitStaysVisibleAndIdempotentAcrossLfAndCrlf(): void
    {
        $snapshot = self::runFullSetTwice([
            'LazyLfTest.php' => self::LAZY_CORPUS,
            'LazyCrlfTest.php' => self::toCrlf(self::LAZY_CORPUS),
            'EagerControlTest.php' => self::EAGER_CONTROL_CORPUS,
        ]);

        $lf = Assert::string($snapshot['LazyLfTest.php']);

        // The gap is visible: the canonical residual marker, attributed to the
        // detector rule, names the lazy strategy.
        $lf->contains(
            'laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, '
            . 'rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual)',
        );
        $lf->contains(
            'Illuminate\Foundation\Testing\LazilyRefreshDatabase trait'
            . ' — the lazy database refresh strategy has no automatic conversion; migrate manually',
        );

        // The source trait stays available for the manual migration...
        $lf->contains('use LazilyRefreshDatabase;');

        // ...while the rest of the class still migrates.
        $lf->contains('Laratesto\Testing\LaravelTestCase');
        $lf->contains('#[\Testo\Test]');

        // The same contract on the CRLF corpus, compared after newline
        // normalization; the corpus itself carried raw CRLF bytes in.
        $crlf = Assert::string(self::toLf($snapshot['LazyCrlfTest.php']));
        $crlf->contains('laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION');
        $crlf->contains(
            'Illuminate\Foundation\Testing\LazilyRefreshDatabase trait'
            . ' — the lazy database refresh strategy has no automatic conversion; migrate manually',
        );
        $crlf->contains('use LazilyRefreshDatabase;');
        $crlf->contains('Laratesto\Testing\LaravelTestCase');
        $crlf->contains("migrate manually */\nfinal class LazyTest extends TestCase");

        $control = Assert::string(self::toLf($snapshot['EagerControlTest.php']));

        // The ordinary eager strategy still converts clean: attribute instead of the
        // trait, and no residual anywhere.
        $control->contains('#[\Laratesto\Attribute\RefreshDatabase]');
        $control->notContains('use RefreshDatabase;');
        $control->notContains('laratesto-residual');
    }

    private static function toCrlf(string $contents): string
    {
        return str_replace("\n", "\r\n", $contents);
    }

    private static function toLf(string $contents): string
    {
        return str_replace("\r\n", "\n", $contents);
    }

    /**
     * Runs the real `rector` binary with the full public set over a throwaway corpus
     * copy, twice, and returns the corpus after the first run — asserting on the way
     * that the first run changed something and the second left every file raw
     * byte-identical. argv-array invocation bypasses the shell: no escaping needed on
     * any platform.
     *
     * @param array<string, string> $files Corpus file name => contents
     *
     * @return array<string, string> Corpus file name => raw contents after run 1
     */
    private static function runFullSetTwice(array $files): array
    {
        $rootDir = \dirname(__DIR__, 4);
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';

        $tmpDir = \sys_get_temp_dir() . '/pr8-lazy-db-fix-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        self::recursiveRemove($tmpDir);

        $corpusDir = $tmpDir . '/corpus';
        Assert::true(\mkdir($corpusDir, 0777, true) || \is_dir($corpusDir));

        try {
            foreach ($files as $name => $contents) {
                \file_put_contents($corpusDir . '/' . $name, $contents);
            }

            // The CRLF twin must genuinely carry CRLF bytes in, or the LF/CRLF proof
            // below is vacuous.
            Assert::true(str_contains($files['LazyCrlfTest.php'], "\r\n"), 'The CRLF corpus was not written with CRLF line endings.');

            // Config written inside the tmp tree so relative paths always point at the copy.
            $withPaths = \var_export($corpusDir, true);
            $sets = \var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            \file_put_contents(
                $tmpDir . '/rector.php',
                <<<PHP
                <?php

                declare(strict_types=1);

                use Rector\Config\RectorConfig;

                return RectorConfig::configure()
                    ->withPaths([{$withPaths}])
                    ->withSets([{$sets}]);
                PHP,
            );

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

            // Run 1: allowed to modify — and must actually change at least one input
            // file, or the corpus is stale and the idempotency proof is vacuous.
            // Run 2: strictly forbidden to touch a single raw byte.
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
     * @return array<string, string>
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

            is_dir($path) ? self::recursiveRemove($path) : \unlink($path);
        }

        \rmdir($dir);
    }
}
