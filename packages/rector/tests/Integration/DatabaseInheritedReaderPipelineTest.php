<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * Inherited-reader regression: the database preflight lifted the converting
 * class's own shadowing option property into the attribute and deleted it, but
 * every PROJECT method above the class kept executing. While the child carried
 * `protected bool $seed = false`, an ancestor's `observedSeed()` answered the
 * child's slot; after the lift the same read silently repointed at the
 * ancestor's `true`. The preflight now walks the resolved ancestor chain, the
 * project traits those ancestors compose, and the project traits the converting
 * class itself composes (trait methods execute with the consumer's scope), and
 * fails the lift closed with DATABASE_UNSUPPORTED_CONFIGURATION whenever a live
 * reader is not provably inert. Proven inert: no reader at all, and the
 * reader's own scope owning a private slot of the name — a more-derived
 * redeclare never shadows it there, so the answer is identical before and after
 * the lift (runtime contract probes pin those slot semantics). A private slot
 * in any other scope shields nothing, and equal literals are not chased by a
 * value-proof engine: live readers receive a stable residual.
 *
 * The pipeline runs once per line-ending spelling: Rector preserves the input's
 * EOL style, and a Git checkout with core.autocrlf=true hands the nowdoc corpus
 * to PHP with CRLF, so both LF and CRLF input must reach the same fail-closed
 * classification. The second-run idempotency check compares the raw file bytes
 * with no line-ending normalization.
 */
final class DatabaseInheritedReaderPipelineTest
{
    #[Test]
    public function inheritedReadersFailTheOptionLiftClosed(): void
    {
        $this->assertPipelineOutcome("\n");
    }

    #[Test]
    public function inheritedReadersFailTheOptionLiftClosedOnCrlfInput(): void
    {
        $this->assertPipelineOutcome("\r\n");
    }

    /**
     * Runs the migration over the corpus spelled with the given line ending and
     * asserts the per-class classification plus the raw byte idempotency of a
     * second run.
     */
    private function assertPipelineOutcome(string $lineEnding): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-reader-' . getmypid()
            . ($lineEnding === "\n" ? '-lf' : '-crlf');
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        try {
            // The nowdoc spelling depends on the checkout's line endings, so both
            // variants are canonicalized to LF first and then spelled with the
            // ending under test.
            $corpus = static function (string $source) use ($lineEnding): string {
                $source = str_replace("\r\n", "\n", $source);

                return str_replace("\n", $lineEnding, $source);
            };

            file_put_contents($tmpDir . '/corpus/Base.php', $corpus(<<<'PHP'
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    protected bool $seed = true;

    public function observedSeed(): bool
    {
        return $this->seed;
    }
}

abstract class QuietCase extends FoundationTestCase
{
    protected bool $seed = true;
}

abstract class PrivateCase extends FoundationTestCase
{
    private bool $seed = true;

    public function observedSeed(): bool
    {
        return $this->seed;
    }
}

trait ChildReader
{
    public function observedSeed(): bool
    {
        return $this->seed;
    }
}
PHP));
            file_put_contents($tmpDir . '/corpus/Tests.php', $corpus(<<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ChildReader;
use Tests\PrivateCase;
use Tests\QuietCase;

final class RegressionTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    public function test_ok(): void {}
}

final class ReaderlessShadowTest extends \Tests\QuietCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    public function test_ok(): void {}
}

final class PrivateSlotTest extends \Tests\PrivateCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    public function test_ok(): void {}
}

final class ChildTraitReaderTest extends \Tests\QuietCase
{
    use RefreshDatabase;
    use ChildReader;

    protected bool $seed = false;

    public function test_ok(): void {}
}
PHP));

            $paths = var_export($tmpDir . '/corpus', true);
            $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            file_put_contents($tmpDir . '/rector.php', <<<PHP
<?php

declare(strict_types=1);

use Laratesto\\Rector\\Rules\\LaravelBaseClassRector;
use Rector\\Config\\RectorConfig;

return RectorConfig::configure()
    ->withPaths([{$paths}])
    ->withSets([{$set}])
    ->withConfiguredRule(LaravelBaseClassRector::class, [
        LaravelBaseClassRector::BASE_CLASSES => [
            'Tests\\\\TestCase',
            'Tests\\\\QuietCase',
            'Tests\\\\PrivateCase',
            'Illuminate\\\\Foundation\\\\Testing\\\\TestCase',
        ],
    ]);
PHP);

            $this->runRector($rootDir, $tmpDir);

            // Marker and attribute adjacency is asserted on the full normalized
            // output: every prefix below is class-bound (the residual reasons are
            // unique per class, the attribute is followed by the class name), so
            // the exact adjacency proves which class got which outcome. The
            // per-class slices then pin the body: what stayed and what moved.

            $base = str_replace("\r\n", "\n", (string) file_get_contents($tmpDir . '/corpus/Base.php'));
            $tests = str_replace("\r\n", "\n", (string) file_get_contents($tmpDir . '/corpus/Tests.php'));

            $between = static function (string $haystack, string $from, string $to): string {
                $start = strpos($haystack, $from);
                $end = strpos($haystack, $to);

                return substr($haystack, (int) $start, $end === false ? null : $end - $start);
            };

            // The regression shape: the ancestor's observedSeed() reads the
            // shadowing child declaration today, so the lift must fail closed
            // with the reader named — the marker sits directly above the class,
            // the trait use and the child property both stay.
            Assert::string($tests)->contains(
                'database option $seed is read by project code in Tests\TestCase and requires manual migration */'
                . "\n" . 'final class RegressionTest extends \Tests\TestCase',
            );
            $regression = $between($tests, 'final class RegressionTest', 'final class ReaderlessShadowTest');
            Assert::string($regression)->contains('use RefreshDatabase;');
            Assert::string($regression)->contains('protected bool $seed = false;');

            // No reader anywhere: the supported shadowing lift stays lossless.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\RefreshDatabase(seed: false)]\n"
                . 'final class ReaderlessShadowTest extends \Tests\QuietCase',
            );
            $readerless = $between($tests, 'final class ReaderlessShadowTest', 'final class PrivateSlotTest');
            Assert::string($readerless)->notContains('protected bool $seed');

            // The reader's own scope owns the private slot: the redeclare never
            // shadows it there, so the read answers identically after the lift.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\RefreshDatabase(seed: false)]\n"
                . 'final class PrivateSlotTest extends \Tests\PrivateCase',
            );
            $privateSlot = $between($tests, 'final class PrivateSlotTest', 'final class ChildTraitReaderTest');
            Assert::string($privateSlot)->notContains('protected bool $seed');

            // A reader inside the converting class's own project trait executes
            // with the child's scope and survives the conversion: fail closed,
            // naming the trait, keeping the trait use and the child property.
            Assert::string($tests)->contains(
                'database option $seed is read by project code in Tests\ChildReader and requires manual migration */'
                . "\n" . 'final class ChildTraitReaderTest extends \Tests\QuietCase',
            );
            $childTrait = $between($tests, 'final class ChildTraitReaderTest', "\0");
            Assert::string($childTrait)->contains('use RefreshDatabase;' . "\n" . '    use ChildReader;');
            Assert::string($childTrait)->contains('protected bool $seed = false;');

            // The bases keep their declarations and readers untouched.
            Assert::string($base)->contains('protected bool $seed = true;');
            Assert::string($base)->contains('private bool $seed = true;');

            // Re-running the migration over its own output changes nothing: the
            // markers reconcile and the conversions stay byte-identical — compared
            // on the raw file bytes, with no line-ending normalization.
            $afterFirstRun = (string) file_get_contents($tmpDir . '/corpus/Tests.php');

            $this->runRector($rootDir, $tmpDir);
            Assert::same($afterFirstRun, (string) file_get_contents($tmpDir . '/corpus/Tests.php'));
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    /**
     * @return array{int, string} Process exit code and combined output.
     */
    private function runRectorProcess(string $rootDir, string $tmpDir): array
    {
        $process = new Process(
            [PHP_BINARY, $rootDir . '/vendor/rector/rector/bin/rector', 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi'],
            $rootDir,
            timeout: 300.0,
        );
        $process->run();

        return [$process->getExitCode(), $process->getOutput() . $process->getErrorOutput()];
    }

    private function runRector(string $rootDir, string $tmpDir): void
    {
        [$exitCode, $output] = $this->runRectorProcess($rootDir, $tmpDir);

        Assert::same(0, $exitCode, $output);
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

            if (is_dir($path)) {
                self::recursiveRemove($path);

                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}
