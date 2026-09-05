<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * PR #8 regression: lifting RefreshDatabase's `$connectionsToTransact` into the
 * attribute's `connections` argument silently CHANGES which databases
 * migrate:fresh refreshes. The source trait runs its single migrate:fresh
 * against the DEFAULT connection no matter what the selection contains — the
 * selection only picks which connections receive the per-test transactions —
 * while the attribute's connections argument repoints migrate:fresh at every
 * selected connection (`#[RefreshDatabase(connections: ['secondary'])]` wipes
 * the secondary schema and never migrates the default one, and an empty list
 * migrates nothing at all). The lift is therefore fail-closed with
 * DATABASE_UNSUPPORTED_CONFIGURATION for every named, multiple or empty
 * selection, and only a provably default-only selection (a single null entry)
 * converts — into the attribute's bare form, whose absent argument resolves to
 * the same default-only migration and transaction scope.
 *
 * The pipeline runs the REAL Rector binary over the full set once per
 * line-ending spelling: Rector preserves the input's EOL style, and a Git
 * checkout with core.autocrlf=true hands the nowdoc corpus to PHP with CRLF, so
 * both LF and CRLF input must reach the same retained-trait-plus-residual
 * classification. The second run compares the raw file bytes, so a marker or a
 * conversion that reconciles differently on re-application fails here.
 */
final class DatabaseMigrationScopePipelineTest
{
    #[Test]
    public function connectionSelectionsKeepTheTraitOnLfInput(): void
    {
        $this->assertPipelineOutcome("\n");
    }

    #[Test]
    public function connectionSelectionsKeepTheTraitOnCrlfInput(): void
    {
        $this->assertPipelineOutcome("\r\n");
    }

    private function assertPipelineOutcome(string $lineEnding): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-scope-' . getmypid()
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

use Illuminate\Foundation\Testing\RefreshDatabase;

final class NamedSecondaryTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = ['secondary'];

    public function test_ok(): void {}
}

final class MultipleSelectionsTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = ['sqlite', 'secondary'];

    public function test_ok(): void {}
}

final class EmptySelectionTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    public function test_ok(): void {}
}

final class NullDefaultSelectionTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [null];

    public function test_ok(): void {}
}

final class PlainControlTest extends \Tests\TestCase
{
    use RefreshDatabase;

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

            $scopeReason = 'database option $connectionsToTransact changes the migration scope: '
                . 'the trait always runs migrate:fresh on the default connection, while the '
                . 'attribute would refresh the selected connections - migrate it manually';

            // A named selection keeps the source trait AND its property: lifting
            // it would repoint migrate:fresh from the default connection at the
            // selected one and silently wipe a different schema.
            Assert::string($tests)->contains(
                "/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): {$scopeReason} */\n"
                . 'final class NamedSecondaryTest extends \Tests\TestCase' . "\n{\n    use RefreshDatabase;\n\n    protected array \$connectionsToTransact = ['secondary'];",
            );

            // Multiple named selections fail closed for the same reason.
            Assert::string($tests)->contains(
                "/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): {$scopeReason} */\n"
                . 'final class MultipleSelectionsTest extends \Tests\TestCase' . "\n{\n    use RefreshDatabase;\n\n    protected array \$connectionsToTransact = ['sqlite', 'secondary'];",
            );

            // The empty selection keeps the trait too: the source trait still
            // migrates the default connection, while the attribute's empty list
            // would migrate none.
            Assert::string($tests)->contains(
                '/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTransact has an unsupported literal shape */'
                . "\nfinal class EmptySelectionTest extends \\Tests\\TestCase\n{\n    use RefreshDatabase;\n\n    protected array \$connectionsToTransact = [];",
            );

            // A single null entry selects exactly the default connection for the
            // transactions — the same default-only scope the attribute's absent
            // argument expresses — so the declaration lifts into the bare
            // attribute and both the trait use and the property disappear.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\RefreshDatabase]\n"
                . 'final class NullDefaultSelectionTest extends \Tests\TestCase' . "\n{\n",
            );

            $between = static function (string $haystack, string $from, string $to): string {
                $start = strpos($haystack, $from);
                $end = $to === "\0" ? null : strpos($haystack, $to);

                return substr($haystack, (int) $start, $end === false ? null : $end - $start);
            };

            $nullBlock = $between($tests, 'final class NullDefaultSelectionTest', 'final class PlainControlTest');
            Assert::string($nullBlock)->notContains('use RefreshDatabase;');
            Assert::string($nullBlock)->notContains('connectionsToTransact');

            // Control: the plain trait use converts to the bare attribute.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\RefreshDatabase]\n"
                . 'final class PlainControlTest extends \Tests\TestCase' . "\n{\n",
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
            $path = $dir . '/' . $item;
            if ($item === '.' || $item === '..') {
                continue;
            }
            is_dir($path) ? self::recursiveRemove($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
