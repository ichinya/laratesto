<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * PR #8 regression: lifting DatabaseTruncation's `$connectionsToTruncate` into
 * the attribute's `connections` argument silently CHANGES migration and seeding
 * targets. The source trait scopes ONLY the table truncation with the selection
 * — its first migrate:fresh (through `migrateFreshUsing()`, no `--database`)
 * and its later db:seed calls always run against the DEFAULT connection — while
 * the attribute's connections argument repoints BOTH at every selected
 * connection (`#[DatabaseTruncation(connections: ['secondary'])]` wipes and
 * seeds the secondary schema and never migrates or seeds the default one, and
 * an empty list migrates nothing at all). The lift is therefore fail-closed
 * with DATABASE_UNSUPPORTED_CONFIGURATION for every named, multiple, duplicate
 * or empty selection, and only a provably default-only selection (a single
 * null entry) converts — into the attribute's bare form, whose absent argument
 * resolves to the same default-only migration, seeding and truncation scope.
 * DatabaseTransactions keeps its selection mapping: its transactions really do
 * wrap every selected connection, so that lift stays lossless.
 *
 * The pipeline runs the REAL Rector binary over the full set once per
 * line-ending spelling: Rector preserves the input's EOL style, and a Git
 * checkout with core.autocrlf=true hands the nowdoc corpus to PHP with CRLF, so
 * both LF and CRLF input must reach the same retained-trait-plus-residual
 * classification. The second run compares the raw file bytes, so a marker or a
 * conversion that reconciles differently on re-application fails here.
 */
final class DatabaseTruncationScopePipelineTest
{
    #[Test]
    public function truncationSelectionsKeepTheTraitOnLfInput(): void
    {
        $this->assertPipelineOutcome("\n");
    }

    #[Test]
    public function truncationSelectionsKeepTheTraitOnCrlfInput(): void
    {
        $this->assertPipelineOutcome("\r\n");
    }

    private function assertPipelineOutcome(string $lineEnding): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-truncation-scope-' . getmypid()
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

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;

final class NamedSecondaryTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    protected bool $seed = true;

    protected array $connectionsToTruncate = ['secondary'];

    public function test_ok(): void {}
}

final class MultipleSelectionsTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['sqlite', 'secondary'];

    public function test_ok(): void {}
}

final class DuplicatesSelectionTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['secondary', 'secondary'];

    public function test_ok(): void {}
}

final class EmptySelectionTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [];

    public function test_ok(): void {}
}

final class NullDefaultSelectionTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    protected bool $seed = true;

    protected array $connectionsToTruncate = [null];

    protected array $tablesToTruncate = ['orders'];

    public function test_ok(): void {}
}

final class PlainControlTest extends \Tests\TestCase
{
    use DatabaseTruncation;

    public function test_ok(): void {}
}

final class TransactionsControlTest extends \Tests\TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['sqlite', 'secondary'];

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

            $scopeReason = 'database option $connectionsToTruncate changes the migration and seeding scope: '
                . 'the trait only truncates the selected connections while its first migrate:fresh and '
                . 'every later db:seed run on the default connection, but the attribute would migrate '
                . 'and seed every selected connection - migrate it manually';

            $marker = "/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): {$scopeReason} */\n";

            // A named selection keeps the source trait AND its property: lifting
            // it would repoint the first migrate:fresh and the later db:seed
            // from the default connection at the selected one.
            Assert::string($tests)->contains(
                $marker
                . 'final class NamedSecondaryTest extends \Tests\TestCase' . "\n{\n    use DatabaseTruncation;\n\n    protected bool \$seed = true;\n\n    protected array \$connectionsToTruncate = ['secondary'];",
            );

            // Multiple named selections fail closed for the same reason.
            Assert::string($tests)->contains(
                $marker
                . 'final class MultipleSelectionsTest extends \Tests\TestCase' . "\n{\n    use DatabaseTruncation;\n\n    protected array \$connectionsToTruncate = ['sqlite', 'secondary'];",
            );

            // Duplicates resolve to the same selection at runtime — still a
            // named selection, still fail-closed.
            Assert::string($tests)->contains(
                $marker
                . 'final class DuplicatesSelectionTest extends \Tests\TestCase' . "\n{\n    use DatabaseTruncation;\n\n    protected array \$connectionsToTruncate = ['secondary', 'secondary'];",
            );

            // The empty selection keeps the trait too: the source trait still
            // migrates and seeds the default connection, while the attribute's
            // empty list would migrate and seed none.
            Assert::string($tests)->contains(
                '/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTruncate has an unsupported literal shape */'
                . "\nfinal class EmptySelectionTest extends \\Tests\\TestCase\n{\n    use DatabaseTruncation;\n\n    protected array \$connectionsToTruncate = [];",
            );

            // A single null entry selects exactly the default connection for
            // the truncation — the same default-only scope the attribute's
            // absent argument expresses — so the declaration lifts into the
            // bare attribute (safe sibling options survive) and both the trait
            // use and the property disappear.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\DatabaseTruncation(seed: true, tables: ['orders'])]\n"
                . 'final class NullDefaultSelectionTest extends \Tests\TestCase' . "\n{\n",
            );

            $between = static function (string $haystack, string $from, string $to): string {
                $start = strpos($haystack, $from);
                $end = $to === "\0" ? null : strpos($haystack, $to);

                return substr($haystack, (int) $start, $end === false ? null : $end - $start);
            };

            $nullBlock = $between($tests, 'final class NullDefaultSelectionTest', 'final class PlainControlTest');
            Assert::string($nullBlock)->notContains('use DatabaseTruncation;');
            Assert::string($nullBlock)->notContains('connectionsToTruncate');

            // Control: the plain trait use converts to the bare attribute.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\DatabaseTruncation]\n"
                . 'final class PlainControlTest extends \Tests\TestCase' . "\n{\n",
            );

            // DatabaseTransactions keeps its selection mapping: its transactions
            // really wrap every selected connection, so that lift is lossless.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\DatabaseTransactions(connections: ['sqlite', 'secondary'])]\n"
                . 'final class TransactionsControlTest extends \Tests\TestCase' . "\n{\n",
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
