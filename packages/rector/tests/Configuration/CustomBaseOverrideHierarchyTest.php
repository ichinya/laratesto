<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Configuration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * Exercises Rector's real container with a custom base_classes override, so this
 * proves the override governs ONE shared hierarchy state across every Laravel rule:
 * a descendant of the configured base gains database attributes, response rewrites
 * and test marking in the same run, while a class extending a base that the
 * override dropped is never partially mutated and only gets a residual marker.
 */
final class CustomBaseOverrideHierarchyTest
{
    #[Test]
    public function customOverrideConvertsItsDescendantsAndSparesEverythingElse(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-override-' . getmypid();
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        try {
            file_put_contents($tmpDir . '/corpus/ProjectTestCase.php', <<<'PHP'
<?php

namespace Illuminate\Foundation\Testing {
    abstract class TestCase {}
}

namespace Acme\Testing {
    abstract class ProjectTestCase extends \Illuminate\Foundation\Testing\TestCase {}
}
PHP);
            file_put_contents($tmpDir . '/corpus/InScopeTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

use Acme\Testing\ProjectTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

final class InScopeTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function visit(): TestResponse
    {
        return $this->postJson('/signup', ['email' => 'a@b.c'])
            ->assertStatus(201);
    }

    public function test_in_scope(): void {}
}
PHP);
            file_put_contents($tmpDir . '/corpus/OutOfScopeTest.php', <<<'PHP'
<?php

namespace Tests {
    abstract class TestCase {}
}

namespace Tests\Feature {

    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Testing\TestResponse;

    final class OutOfScopeTest extends \Tests\TestCase
    {
        use RefreshDatabase;

        public function visit(): TestResponse
        {
            return $this->postJson('/signup', ['email' => 'a@b.c'])
                ->assertStatus(201);
        }

        public function test_out_of_scope(): void {}
    }
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
            'Acme\\\\Testing\\\\ProjectTestCase',
            'Illuminate\\\\Foundation\\\\Testing\\\\TestCase',
        ],
    ]);
PHP);

            $this->runRector($rootDir, $tmpDir);

            $projectBase = (string) file_get_contents($tmpDir . '/corpus/ProjectTestCase.php');
            $inScope = (string) file_get_contents($tmpDir . '/corpus/InScopeTest.php');
            $outOfScope = (string) file_get_contents($tmpDir . '/corpus/OutOfScopeTest.php');

            // The configured project base itself converts to the Laratesto base.
            Assert::string($projectBase)->contains('extends \Laratesto\Testing\LaravelTestCase');

            // The descendant converts as a whole: database trait to attribute, response
            // type rewritten, test method marked — and the hierarchy is kept.
            Assert::string($inScope)->contains('#[\Laratesto\Attribute\RefreshDatabase]');
            Assert::string($inScope)->notContains('use Illuminate\Foundation\Testing\RefreshDatabase;');
            Assert::string($inScope)->contains('\Laratesto\Testing\LaravelResponse');
            Assert::string($inScope)->notContains('TestResponse');
            Assert::string($inScope)->contains('#[\Testo\Test]');
            Assert::string($inScope)->contains('extends ProjectTestCase');
            Assert::string($inScope)->notContains('laratesto-residual');

            // A configured custom hierarchy never lands in the outside-hierarchy
            // diagnosis: the chain reaches the configured bases, so nothing is offered.
            Assert::string($inScope)->notContains('outside a convertible hierarchy');
            Assert::string($inScope)->notContains('resolvable but missing from base_classes');

            // `Tests\TestCase` was dropped by the override: no rule may partially
            // migrate the class, it only becomes visible as a residual.
            Assert::string($outOfScope)->contains('extends \Tests\TestCase');
            Assert::string($outOfScope)->contains('use Illuminate\Foundation\Testing\RefreshDatabase;');
            Assert::string($outOfScope)->contains('TestResponse');
            Assert::string($outOfScope)->notContains('#[\Laratesto\Attribute\RefreshDatabase]');
            Assert::string($outOfScope)->notContains('LaravelResponse');
            Assert::string($outOfScope)->notContains('#[\Testo\Test]');
            Assert::string($outOfScope)->contains('laratesto-residual(code=LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY');

            // `Tests\TestCase` (dropped by the override) dead-ends below the configured
            // bases: the conservative wording stays and no --base-class fix is offered.
            Assert::string($outOfScope)->contains('base class does not resolve; migrate manually');
            Assert::string($outOfScope)->notContains('resolvable but missing from base_classes');
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    #[Test]
    public function anUnknownConfigurationKeyFailsTheRun(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-typo-' . getmypid();
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        try {
            file_put_contents($tmpDir . '/corpus/SimpleTest.php', <<<'PHP'
<?php

namespace Illuminate\Foundation\Testing {
    abstract class TestCase {}
}

namespace Tests\Feature {
    final class SimpleTest extends \Illuminate\Foundation\Testing\TestCase
    {
        public function test_simple(): void {}
    }
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
        'base_clases' => ['Acme\\\\Testing\\\\ProjectTestCase'],
    ]);
PHP);

            [$exitCode, $output] = $this->runRectorProcess($rootDir, $tmpDir);

            Assert::notSame(0, $exitCode, "rector should fail on the unknown key.\n{$output}");
            Assert::string($output)->contains('Unsupported configuration key(s): base_clases');
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
        // (a failing configuration) cannot deadlock the run. Finite timeout so a hung
        // binary fails the test instead of stalling it forever.
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
