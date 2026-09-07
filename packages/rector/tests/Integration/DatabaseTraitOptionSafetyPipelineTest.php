<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * Finding 2 (GLM cold review): option properties supplied by non-Laravel project
 * traits flatten into the consuming class at runtime, but the analyzer only scanned
 * Class_::getProperties() — so `use RefreshDatabase` plus `use ProjectOptions {
 * protected bool $seed = true; }` silently migrated to a default #[RefreshDatabase]
 * and lost seeding (likewise DatabaseTruncation tables/connections). The preflight
 * now resolves directly/nested used project traits for the converted class and the
 * applicable ancestor chain (same-file first, then AstResolver), inspects their full
 * composition trees, and fails the conversion closed with
 * DATABASE_UNSUPPORTED_CONFIGURATION wherever a live option cannot be ruled out.
 *
 * A trait that cannot be resolved cannot be proven option-free and blocks too; a
 * cyclic composition never reaches the analyzer because PHP refuses to compile the
 * forward reference, and the diamond re-visit terminates on the seen-set.
 *
 * The pipeline runs once per line-ending spelling: Rector preserves the input's
 * EOL style, and a Git checkout with core.autocrlf=true hands the nowdoc corpus
 * to PHP with CRLF, so both LF and CRLF input must reach the same fail-closed
 * classification.
 */
final class DatabaseTraitOptionSafetyPipelineTest
{
    #[Test]
    public function projectTraitOptionsFailTheTraitConversionClosed(): void
    {
        $this->assertPipelineOutcome("\n");
    }

    #[Test]
    public function projectTraitOptionsFailTheTraitConversionClosedOnCrlfInput(): void
    {
        $this->assertPipelineOutcome("\r\n");
    }

    /**
     * Runs the migration over the corpus spelled with the given line ending and
     * asserts the per-class fail-closed/converted outcomes. The semantic adjacency
     * assertions run on line-ending-normalized content — they pin the marker and
     * attribute attachment, not the file's EOL style — while the second-run
     * idempotency assertion compares the raw file bytes.
     */
    private function assertPipelineOutcome(string $lineEnding): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-trait-' . getmypid()
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

trait DatabaseDefaults
{
    protected bool $seed = true;

    protected array $connectionsToTruncate = ['archive'];
}

trait AncestorDefaults
{
    protected bool $dropViews = true;
}

trait CleanHelper
{
    protected string $label = 'helper';

    public function label(): string
    {
        return $this->label;
    }
}

abstract class TestCase extends FoundationTestCase
{
}

abstract class TraitBase extends TestCase
{
    use AncestorDefaults;
}
PHP));
            file_put_contents($tmpDir . '/corpus/Tests.php', $corpus(<<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\AncestorDefaults;
use Tests\CleanHelper;
use Tests\DatabaseDefaults;
use Vendor\Missing\NotInstallable;

final class CrossFileTraitTest extends \Tests\TestCase
{
    use RefreshDatabase;
    use DatabaseDefaults;

    public function test_ok(): void {}
}

final class TruncationTraitTest extends \Tests\TestCase
{
    use DatabaseTruncation;
    use DatabaseDefaults;

    public function test_ok(): void {}
}

final class AncestorTraitTest extends \Tests\TraitBase
{
    use RefreshDatabase;

    public function test_ok(): void {}
}

final class CleanTraitTest extends \Tests\TestCase
{
    use RefreshDatabase;
    use CleanHelper;

    public function test_ok(): void {}
}

final class UnresolvableTraitTest extends \Tests\TestCase
{
    use RefreshDatabase;
    use NotInstallable;

    public function test_ok(): void {}
}

final class PlainControlTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_ok(): void {}
}
PHP));
            file_put_contents($tmpDir . '/corpus/Controls.php', $corpus(<<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Control;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CleanHelper;

final class CleanTraitTest extends \Illuminate\Foundation\Testing\TestCase
{
    use RefreshDatabase;
    use CleanHelper;

    public function test_ok(): void {}
}

final class PlainControlTest extends \Illuminate\Foundation\Testing\TestCase
{
    use RefreshDatabase;

    public function test_ok(): void {}
}
PHP));

            $paths = var_export($tmpDir . '/corpus', true);
            $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            $cache = var_export($tmpDir . '/cache', true);
            file_put_contents($tmpDir . '/rector.php', <<<PHP
<?php

declare(strict_types=1);

use Laratesto\\Rector\\Rules\\LaravelBaseClassRector;
use Rector\\Config\\RectorConfig;

return RectorConfig::configure()
    ->withPaths([{$paths}])
    ->withSets([{$set}])
    ->withCache(cacheDirectory: {$cache})
    ->withConfiguredRule(LaravelBaseClassRector::class, [
        LaravelBaseClassRector::BASE_CLASSES => [
            'Tests\\\\TestCase',
            'Tests\\\\TraitBase',
            'Illuminate\\\\Foundation\\\\Testing\\\\TestCase',
        ],
    ]);
PHP);

            $this->runRector($rootDir, $tmpDir);

            // Semantic checks run on line-ending-normalized content: Rector
            // preserves the input's EOL style, and the assertions below pin the
            // marker and attribute adjacency, not the file's EOL style.
            $base = str_replace("\r\n", "\n", (string) file_get_contents($tmpDir . '/corpus/Base.php'));
            $tests = str_replace("\r\n", "\n", (string) file_get_contents($tmpDir . '/corpus/Tests.php'));
            $controls = str_replace("\r\n", "\n", (string) file_get_contents($tmpDir . '/corpus/Controls.php'));

            // Every marker is attached directly to its own class declaration and
            // every converted class carries its attribute directly, so the exact
            // adjacency fragments below pin each outcome to one class without any
            // ambiguity between neighbours.

            // Cross-file trait resolution: the trait is defined in another processed
            // file, and its $seed is live for the RefreshDatabase machinery through
            // the composition — the conversion fails closed instead of silently
            // dropping the seeding.
            Assert::string($tests)->contains(
                "database option \$seed on trait Tests\DatabaseDefaults requires manual migration */\n"
                . 'final class CrossFileTraitTest extends \Tests\TestCase' . "\n{\n    use RefreshDatabase;\n    use DatabaseDefaults;",
            );

            // The same trait supplies DatabaseTruncation's truncation options — the
            // first option in the trait blocks that conversion too.
            Assert::string($tests)->contains(
                "database option \$seed on trait Tests\DatabaseDefaults requires manual migration */\n"
                . 'final class TruncationTraitTest extends \Tests\TestCase' . "\n{\n    use DatabaseTruncation;\n    use DatabaseDefaults;",
            );

            // The option trait is used by the configured project base itself: the
            // descendant inherits the flattened property exactly like an inline
            // declaration, so its own conversion fails closed too.
            Assert::string($tests)->contains(
                "database option \$dropViews on trait Tests\AncestorDefaults requires manual migration */\n"
                . 'final class AncestorTraitTest extends \Tests\TraitBase' . "\n{\n    use RefreshDatabase;",
            );

            // The unresolved sibling may supply a Laravel lifecycle method. Its
            // shared source base and otherwise safe siblings must remain together.
            Assert::string($base)->contains('abstract class TestCase extends FoundationTestCase');
            Assert::string($base)->contains('descendant Tests\Feature\UnresolvableTraitTest');
            Assert::string($base)->notContains('extends \Laratesto\Testing\LaravelTestCase');
            Assert::string($tests)->contains(
                'final class CleanTraitTest extends \Tests\TestCase' . "\n{\n    use RefreshDatabase;\n    use CleanHelper;",
            );
            Assert::string($tests)->contains(
                'final class PlainControlTest extends \Tests\TestCase' . "\n{\n    use RefreshDatabase;",
            );
            Assert::string($tests)->notContains('#[\Laratesto\Attribute\RefreshDatabase]');
            Assert::string($tests)->notContains('#[\Testo\Test]');

            // A used trait that cannot be resolved cannot be proven option-free —
            // the conversion fails closed instead of guessing.
            Assert::string($tests)->contains(
                "used trait Vendor\Missing\NotInstallable could not be resolved, so its database options require manual migration */\n"
                . 'final class UnresolvableTraitTest extends \Tests\TestCase' . "\n{\n    use RefreshDatabase;\n    use NotInstallable;",
            );

            // Independent hierarchies still convert, including an option-free
            // project trait; neither positive control shares the blocked base.
            Assert::string($controls)->contains(
                "#[\Laratesto\Attribute\RefreshDatabase]\n"
                . 'final class CleanTraitTest extends \Laratesto\Testing\LaravelTestCase' . "\n{\n    use CleanHelper;",
            );
            Assert::string($controls)->contains(
                "#[\Laratesto\Attribute\RefreshDatabase]\n"
                . 'final class PlainControlTest extends \Laratesto\Testing\LaravelTestCase' . "\n{\n",
            );
            Assert::same(2, substr_count($controls, '#[\Testo\Test]'));
            Assert::string($controls)->notContains('use RefreshDatabase;');
            Assert::string($controls)->notContains('laratesto-residual');
            // Re-running the migration over its own output changes nothing: the
            // markers reconcile and the conversions stay byte-identical — compared
            // on the raw file bytes, with no line-ending normalization.
            $afterFirstRun = [];
            foreach (['Base.php', 'Tests.php', 'Controls.php'] as $file) {
                $afterFirstRun[$file] = (string) file_get_contents($tmpDir . '/corpus/' . $file);
            }

            $this->runRector($rootDir, $tmpDir);
            foreach ($afterFirstRun as $file => $contents) {
                Assert::same($contents, (string) file_get_contents($tmpDir . '/corpus/' . $file));
            }
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    /**
     * @return array{int, string} Process exit code and combined output.
     */
    private function runRectorProcess(string $rootDir, string $tmpDir): array
    {
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';
        Assert::true(is_file($rectorBin), 'rector binary not found at ' . $rectorBin);

        // argv-array invocation through Symfony Process: the command never reaches a
        // shell, and the process reads both pipes concurrently — a huge stderr trace
        // cannot deadlock the run. Finite timeout so a hung binary fails the test
        // instead of stalling it forever.
        $process = new Process(
            [PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'],
            $rootDir,
            timeout: 300.0,
        );
        $process->run();

        return [$process->getExitCode() ?? 1, $process->getOutput() . "\n" . $process->getErrorOutput()];
    }

    private function runRector(string $rootDir, string $tmpDir): void
    {
        [$exitCode, $output] = $this->runRectorProcess($rootDir, $tmpDir);

        Assert::same(0, $exitCode, "rector failed.\nOUTPUT:\n{$output}");
    }

    private static function recursiveRemove(string $dir): void
    {
        $items = is_dir($dir) ? scandir($dir) : false;

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            is_dir($path) ? self::recursiveRemove($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
