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
 */
final class DatabaseTraitOptionSafetyPipelineTest
{
    #[Test]
    public function projectTraitOptionsFailTheTraitConversionClosed(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-trait-' . getmypid();
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        try {
            file_put_contents($tmpDir . '/corpus/Base.php', <<<'PHP'
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
PHP);
            file_put_contents($tmpDir . '/corpus/Tests.php', <<<'PHP'
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
PHP);

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
            'Tests\\\\TraitBase',
            'Illuminate\\\\Foundation\\\\Testing\\\\TestCase',
        ],
    ]);
PHP);

            $this->runRector($rootDir, $tmpDir);

            $base = (string) file_get_contents($tmpDir . '/corpus/Base.php');
            $tests = (string) file_get_contents($tmpDir . '/corpus/Tests.php');
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

            // A project trait without database options is unrelated machinery: the
            // conversion proceeds and keeps both the trait use and its import.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\RefreshDatabase]\n"
                . 'final class CleanTraitTest extends \Tests\TestCase' . "\n{\n    use CleanHelper;",
            );

            // A used trait that cannot be resolved cannot be proven option-free —
            // the conversion fails closed instead of guessing.
            Assert::string($tests)->contains(
                "used trait Vendor\Missing\NotInstallable could not be resolved, so its database options require manual migration */\n"
                . 'final class UnresolvableTraitTest extends \Tests\TestCase' . "\n{\n    use RefreshDatabase;\n    use NotInstallable;",
            );

            // Control: a recognized class with only the framework trait converts.
            Assert::string($tests)->contains(
                "#[\Laratesto\Attribute\RefreshDatabase]\n"
                . 'final class PlainControlTest extends \Tests\TestCase' . "\n{\n",
            );

            // Re-running the migration over its own output changes nothing: the
            // markers reconcile and the conversions stay byte-identical.
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
