<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * PR #8 regression: lifting DatabaseTruncation's connection-KEYED
 * `tablesToTruncate`/`exceptTables` maps into the attribute silently CHANGES
 * the table selection under the default-only connection scope. The source
 * trait looks the maps up with the null default selector — the lookup misses
 * every literal connection name and falls back to the whole map, whose array
 * values match no table name, so the trait truncates nothing (or excludes
 * nothing beyond the migrations table) — while the attribute resolves the
 * selector to the connection name first and applies exactly the listed
 * tables. A keyed map therefore converts every table's fate: fail-closed with
 * DATABASE_UNSUPPORTED_CONFIGURATION, trait and property kept. Flat literal
 * string lists and the empty list keep converting — they select the same
 * tables on both sides — and a named connection selection keeps failing
 * closed through the earlier migration-scope guard, which runs before this
 * one.
 *
 * The pipeline runs the REAL Rector binary over the full set once per
 * line-ending spelling: Rector preserves the input's EOL style, and a Git
 * checkout with core.autocrlf=true hands the nowdoc corpus to PHP with CRLF, so
 * both LF and CRLF input must reach the same retained-trait-plus-residual
 * classification. The second run compares the raw file bytes, so a marker or a
 * conversion that reconciles differently on re-application fails here.
 */
final class DatabaseTruncationTableMapPipelineTest
{
    #[Test]
    public function keyedTableMapsKeepTheTraitOnLfInput(): void
    {
        $this->assertPipelineOutcome("\n");
    }

    #[Test]
    public function keyedTableMapsKeepTheTraitOnCrlfInput(): void
    {
        $this->assertPipelineOutcome("\r\n");
    }

    private function assertPipelineOutcome(string $lineEnding): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-table-map-' . getmypid()
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
}
PHP));
            file_put_contents($tmpDir . '/corpus/Tests.php', $corpus(<<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;

final class KeyedTablesTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [null];

    protected array $tablesToTruncate = ['sqlite' => ['things']];

    public function test_ok(): void {}
}

final class KeyedExceptTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    protected array $exceptTables = ['sqlite' => ['audit_entries']];

    public function test_ok(): void {}
}

final class NamedConnectionKeyedTablesTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['secondary'];

    protected array $tablesToTruncate = ['secondary' => ['orders']];

    public function test_ok(): void {}
}

final class FlatListControlTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [null];

    protected array $tablesToTruncate = ['orders'];

    public function test_ok(): void {}
}

final class EmptyListControlTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [];

    public function test_ok(): void {}
}

final class FlatExceptControlTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    protected array $exceptTables = ['audit_entries'];

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
            'Illuminate\\\\Foundation\\\\Testing\\\\TestCase',
        ],
    ]);
PHP);

            $this->runRector($rootDir, $tmpDir);

            // Semantic checks run on line-ending-normalized content: Rector
            // preserves the input's EOL style, and the assertions below pin the
            // marker and attribute attachment, not the file's EOL style.
            $tests = str_replace("\r\n", "\n", (string) file_get_contents($tmpDir . '/corpus/Tests.php'));

            $tablesReason = 'database option $tablesToTruncate keyed by connection name changes the truncation selection: '
                . 'the trait looks the map up with the null default selector, falls back to the whole map that '
                . 'matches no table and truncates nothing, while the attribute would truncate the listed tables '
                . 'on the resolved connection - migrate it manually';
            $exceptReason = 'database option $exceptTables keyed by connection name changes the exclusion selection: '
                . 'the trait looks the map up with the null default selector, falls back to the whole map that '
                . 'matches no table and excludes nothing beyond the migrations table, while the attribute would '
                . 'exclude the listed tables on the resolved connection - migrate it manually';
            $markerFor = static fn (string $reason): string => "/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): {$reason} */\n";

            // A keyed tables map keeps the source trait AND its property: the
            // trait looks it up with the null selector and truncates nothing,
            // while the attribute would truncate the listed tables.
            Assert::string($tests)->contains(
                $markerFor($tablesReason)
                . 'final class KeyedTablesTest extends \Tests\TestCase' . "\n{\n    use DatabaseTruncation;\n\n    protected array \$connectionsToTruncate = [null];\n\n    protected array \$tablesToTruncate = ['sqlite' => ['things']];",
            );

            // The keyed exceptTables map fails closed for the mirrored reason:
            // the trait excludes nothing beyond the migrations table.
            Assert::string($tests)->contains(
                $markerFor($exceptReason)
                . 'final class KeyedExceptTest extends \Tests\TestCase' . "\n{\n    use DatabaseTruncation;\n\n    protected array \$exceptTables = ['sqlite' => ['audit_entries']];",
            );

            // A named connection selection is already residual through the
            // earlier migration-scope guard, which runs before the table-map
            // guard: its reason names the connection option, not the tables.
            $scopeReason = 'database option $connectionsToTruncate changes the migration and seeding scope: '
                . 'the trait only truncates the selected connections while its first migrate:fresh and '
                . 'every later db:seed run on the default connection, but the attribute would migrate '
                . 'and seed every selected connection - migrate it manually';
            Assert::string($tests)->contains(
                $markerFor($scopeReason)
                . 'final class NamedConnectionKeyedTablesTest extends \Tests\TestCase' . "\n{\n    use DatabaseTruncation;\n\n    protected array \$connectionsToTruncate = ['secondary'];",
            );
            $namedBlock = static function (string $haystack): string {
                $start = strpos($haystack, 'final class NamedConnectionKeyedTablesTest');
                $end = strpos($haystack, 'final class FlatListControlTest');

                return substr($haystack, (int) $start, $end - $start);
            };
            Assert::string($namedBlock($tests))->notContains('keyed by connection name');

            // Positive control: the flat list selects the same tables on both
            // sides and converts.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\DatabaseTruncation(tables: ['orders'])]\n"
                . 'final class FlatListControlTest extends \Tests\TestCase' . "\n{\n",
            );

            // Positive control: the empty list keeps the default truncation set
            // on both sides and converts.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\DatabaseTruncation(tables: [])]\n"
                . 'final class EmptyListControlTest extends \Tests\TestCase' . "\n{\n",
            );

            // Positive control: the flat exclusion list converts too.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\DatabaseTruncation(exceptTables: ['audit_entries'])]\n"
                . 'final class FlatExceptControlTest extends \Tests\TestCase' . "\n{\n",
            );

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
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';
        Assert::true(is_file($rectorBin), 'rector binary not found at ' . $rectorBin);

        // argv-array invocation through Symfony Process: the command never reaches a
        // shell, and the process reads both pipes concurrently — a huge stderr trace
        // cannot deadlock the run. Finite timeout so a hung binary fails the test
        // instead of stalling it forever.
        $process = new Process(
            [PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi'],
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
