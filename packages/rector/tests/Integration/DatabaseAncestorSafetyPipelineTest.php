<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * Finding 1 (GLM final review): for configured project hierarchies, a database
 * option property declared on a resolved in-scope ancestor is live for the trait
 * machinery (the traits read their options through `property_exists()`, which sees
 * inherited public/protected members), yet the descendant's trait-to-attribute
 * conversion silently dropped it. The pipeline now fails that conversion closed with
 * DATABASE_UNSUPPORTED_CONFIGURATION by walking the resolved ancestor chain.
 *
 * Proven-live drops only: trait machinery methods declared on an ancestor are NOT
 * scanned — a trait import in the child overrides same-named inherited methods, so
 * such an override never executed and nothing live would be lost.
 */
final class DatabaseAncestorSafetyPipelineTest
{
    #[Test]
    public function ancestorOptionPropertyFailsTheTraitConversionClosed(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-ancestor-' . getmypid();
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        try {
            file_put_contents($tmpDir . '/corpus/Base.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        \unlink(__DIR__ . '/storage.sqlite');
    }
}

abstract class SeedBase extends TestCase
{
    protected bool $seed = true;
}

abstract class IntermediateTestCase extends TestCase
{
    protected array $connectionsToTransact = ['sqlite'];
}
PHP);
            file_put_contents($tmpDir . '/corpus/Tests.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class SeedTest extends \Tests\SeedBase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [null];

    public function test_ok(): void {}
}

final class IntermediateTest extends \Tests\IntermediateTestCase
{
    use DatabaseTransactions;

    public function test_ok(): void {}
}

final class HookControlTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_ok(): void {}
}

final class SafeControlTest extends \Tests\TestCase
{
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
            'Tests\\\\SeedBase',
            'Tests\\\\IntermediateTestCase',
            'Illuminate\\\\Foundation\\\\Testing\\\\TestCase',
        ],
    ]);
PHP);

            $this->runRector($rootDir, $tmpDir);

            $base = (string) file_get_contents($tmpDir . '/corpus/Base.php');
            $tests = (string) file_get_contents($tmpDir . '/corpus/Tests.php');
            $between = static function (string $haystack, string $from, string $to): string {
                $start = strpos($haystack, $from);
                $end = strpos($haystack, $to);

                return substr($haystack, (int) $start, $end === false ? null : $end - $start);
            };

            // The option properties on the configured project bases stay exactly
            // where they are: no rule may lift an ancestor's declaration anywhere.
            Assert::string($base)->contains('extends \Laratesto\Testing\LaravelTestCase');
            Assert::string($base)->contains('protected bool $seed = true;');
            Assert::string($base)->contains("protected array \$connectionsToTransact = ['sqlite'];");

            // The reasons are name-specific, so their presence on the whole file is
            // unambiguous: the ancestor option is named with its declaring ancestor.
            Assert::string($tests)->contains('database option $seed on ancestor Tests\SeedBase requires manual migration');
            Assert::string($tests)->contains('database option $connectionsToTransact on ancestor Tests\IntermediateTestCase requires manual migration');

            // SeedTest: the ancestor's $seed is live for its RefreshDatabase trait
            // (property_exists() sees inherited protected members) — the conversion
            // must fail closed even though the class's own connection selection is
            // provably default-only, keeping the trait and NOT lifting anything
            // into an attribute.
            $seedBlock = $between($tests, 'final class SeedTest', 'final class IntermediateTest');
            Assert::string($seedBlock)->contains('use RefreshDatabase;');
            Assert::string($seedBlock)->contains('protected array $connectionsToTransact = [null];');
            Assert::string($seedBlock)->notContains('#[\Laratesto\Attribute');

            // IntermediateTest: the same for a DatabaseTransactions option on the
            // configured intermediate base — one hop up, still resolved.
            $intermediateBlock = $between($tests, 'final class IntermediateTest', 'final class HookControlTest');
            Assert::string($intermediateBlock)->contains('use DatabaseTransactions;');
            Assert::string($intermediateBlock)->notContains('#[\Laratesto\Attribute\DatabaseTransactions');

            // Hook control: a database hook override on the ancestor is not a live
            // drop — the descendant's own trait import overrides same-named inherited
            // methods, so the hook never executed. It must NOT block the conversion.
            $hookBlock = $between($tests, 'final class HookControlTest', 'final class SafeControlTest');
            Assert::string($hookBlock)->notContains('laratesto-residual');
            Assert::string($tests)->contains("#[\Laratesto\Attribute\RefreshDatabase]\nfinal class HookControlTest");

            // Safe control: without a database trait the ancestor members are inert,
            // so the class converts and stays residual-free.
            $safeBlock = $between($tests, 'final class SafeControlTest', "\0");
            Assert::string($safeBlock)->notContains('laratesto-residual');
            Assert::string($safeBlock)->contains('#[\Testo\Test]');
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
