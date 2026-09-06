<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * PR8 review finding #7: Rector filters the processed file set through
 * `PathSkipper::shouldSkip` (FilesFinder) and drops rule-scoped skips through
 * `Skipper::shouldSkipElementAndFilePath`, but the descendant gate only checked
 * the `paths` option. A project base under `withSkip([...])` therefore counted as
 * "in the run": its descendants were converted - renaming `parent::setUp()` to
 * `parent::setUpLaravel()` - while the skipped base stayed on the PHPUnit
 * hierarchy, so every descendant died with `Call to undefined method` at runtime.
 * Zero errors, zero residuals.
 *
 * The descendant gate now treats the full Rector skip configuration as part of
 * the actual process frontier: a base excluded globally, by glob, or scoped to
 * this rule fails the descendant conversion closed with CLASS_UNSAFE_HIERARCHY.
 *
 * Control hierarchy: a class extending the framework base directly, which the
 * default configuration converts - proving the frontier check is per-file, not
 * a blanket conversion ban.
 */
final class WithSkipFrontierPipelineTest
{
    #[Test]
    public function skippedBaseFailsDescendantConversionClosed(): void
    {
        [$rootDir, $tmpDir] = $this->createCorpus();

        try {
            // Exact-file skip: the project base file is excluded from the run,
            // the control file stays inside it.
            $this->writeConfig($rootDir, $tmpDir, [$tmpDir . '/corpus/Base.php']);

            $this->runRector($rootDir, $tmpDir);

            $base = (string) file_get_contents($tmpDir . '/corpus/Base.php');
            $tests = (string) file_get_contents($tmpDir . '/corpus/Tests.php');

            // The skipped base keeps the PHPUnit hierarchy untouched.
            Assert::string($base)->contains('abstract class TestCase extends FoundationTestCase');
            Assert::string($base)->contains('protected function setUp(): void');
            Assert::string($base)->notContains('Laratesto');
            Assert::string($base)->notContains('laratesto-residual');

            // The descendant fails closed: residual marker, hierarchy and parent
            // lifecycle call preserved. The upstream LifecycleMethodToTestoRector
            // may still decorate setUp with BeforeTest - harmless: the class stays
            // on the PHPUnit hierarchy where parent::setUp() keeps resolving. The
            // marker sits above the class declaration, so its reason is asserted
            // file-wide.
            Assert::string($tests)->contains('project base Tests\TestCase is excluded from this Rector run by the skip configuration');
            $childBlock = self::between($tests, 'final class ChildTest', 'final class ControlTest');
            Assert::string($childBlock)->contains('protected function setUp(): void');
            Assert::string($childBlock)->contains('parent::setUp();');
            Assert::string($childBlock)->notContains('setUpLaravel');
            Assert::string($childBlock)->notContains('laratesto-residual');

            // Control: the framework-child class in a non-skipped file converts.
            $controlBlock = self::between($tests, 'final class ControlTest', "\0");
            Assert::string($controlBlock)->notContains('laratesto-residual');
            Assert::string($controlBlock)->contains('extends \Laratesto\Testing\LaravelTestCase');
            Assert::string($controlBlock)->contains('setUpLaravel');
            Assert::string($controlBlock)->contains('#[\Testo\Test]');

            self::assertCompiles($base);
            self::assertCompiles($tests);

            // Second run must not touch anything.
            $snapshot = [
                'Base.php' => $base,
                'Tests.php' => $tests,
            ];
            $this->runRector($rootDir, $tmpDir);
            Assert::same($snapshot['Base.php'], (string) file_get_contents($tmpDir . '/corpus/Base.php'), 'Second run modified Base.php.');
            Assert::same($snapshot['Tests.php'], (string) file_get_contents($tmpDir . '/corpus/Tests.php'), 'Second run modified Tests.php.');
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    #[Test]
    public function globSkippedBaseFailsDescendantConversionClosed(): void
    {
        [$rootDir, $tmpDir] = $this->createCorpus();

        try {
            // Glob skip: '*/Base.php' matches Base.php but not Tests.php.
            $this->writeConfig($rootDir, $tmpDir, ['*/Base.php']);

            $this->runRector($rootDir, $tmpDir);

            $tests = (string) file_get_contents($tmpDir . '/corpus/Tests.php');

            Assert::string($tests)->contains('project base Tests\TestCase is excluded from this Rector run by the skip configuration');
            $childBlock = self::between($tests, 'final class ChildTest', 'final class ControlTest');
            Assert::string($childBlock)->contains('parent::setUp();');
            Assert::string($childBlock)->notContains('setUpLaravel');

            $controlBlock = self::between($tests, 'final class ControlTest', "\0");
            Assert::string($controlBlock)->contains('setUpLaravel');
            Assert::string($controlBlock)->notContains('laratesto-residual');
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    #[Test]
    public function ruleScopedSkippedBaseFailsDescendantConversionClosed(): void
    {
        [$rootDir, $tmpDir] = $this->createCorpus();

        try {
            // Rule-scoped skip: every OTHER rule still processes Base.php (so the
            // upstream lifecycle decorator may attribute its setUp), but this rule
            // never converts the base - the descendant must fail closed anyway.
            $this->writeConfig($rootDir, $tmpDir, [LaravelBaseClassRector::class => [$tmpDir . '/corpus/Base.php']]);

            $this->runRector($rootDir, $tmpDir);

            $base = (string) file_get_contents($tmpDir . '/corpus/Base.php');
            $tests = (string) file_get_contents($tmpDir . '/corpus/Tests.php');

            // The base keeps its hierarchy: this rule never rewrote it.
            Assert::string($base)->contains('abstract class TestCase extends FoundationTestCase');
            Assert::string($base)->notContains('Laratesto');

            Assert::string($tests)->contains('project base Tests\TestCase is excluded from this Rector run by the skip configuration');
            $childBlock = self::between($tests, 'final class ChildTest', 'final class ControlTest');
            Assert::string($childBlock)->contains('parent::setUp();');
            Assert::string($childBlock)->notContains('setUpLaravel');

            // Control: the framework-child class converts fully.
            $controlBlock = self::between($tests, 'final class ControlTest', "\0");
            Assert::string($controlBlock)->contains('extends \Laratesto\Testing\LaravelTestCase');
            Assert::string($controlBlock)->contains('setUpLaravel');

            self::assertCompiles($tests);

            // Second run must not touch anything.
            $snapshot = $tests;
            $this->runRector($rootDir, $tmpDir);
            Assert::same($snapshot, (string) file_get_contents($tmpDir . '/corpus/Tests.php'), 'Second run modified Tests.php.');
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    /**
     * `Tests\TestCase` (Base.php) -> ChildTest plus a direct framework child
     * ControlTest in the same non-skipped file.
     *
     * @return array{string, string}
     */
    private function createCorpus(): array
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-skip-frontier-' . getmypid();
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        file_put_contents($tmpDir . '/corpus/Base.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }
}
PHP);
        file_put_contents($tmpDir . '/corpus/Tests.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase as FrameworkTestCase;
use Tests\TestCase;

final class ChildTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_ok(): void {}
}

final class ControlTest extends FrameworkTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache');
    }

    public function test_ok(): void {}
}
PHP);

        return [$rootDir, $tmpDir];
    }

    /**
     * @param list<string|array<class-string, list<string>>> $skip
     */
    private function writeConfig(string $rootDir, string $tmpDir, array $skip): void
    {
        $paths = var_export($tmpDir . '/corpus', true);
        $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
        $skip = var_export($skip, true);
        file_put_contents($tmpDir . '/rector.php', <<<PHP
<?php

declare(strict_types=1);

use Rector\\Config\\RectorConfig;

return RectorConfig::configure()
    ->withPaths([{$paths}])
    ->withSets([{$set}])
    ->withSkip({$skip});
PHP);
    }

    private static function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        Assert::true($start !== false, 'Missing expected block start ' . $from);

        $end = strpos($haystack, $to, $start);

        return substr($haystack, $start, $end === false ? null : $end - $start);
    }

    private static function assertCompiles(string $contents): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'laratesto-lint-');
        Assert::true(is_string($tmp) && file_put_contents($tmp, $contents) !== false);

        try {
            $process = new Process([PHP_BINARY, '-l', $tmp], timeout: 30.0);
            $process->run();

            Assert::same(0, $process->getExitCode(), "Output does not pass php -l:\n" . $process->getOutput() . "\n" . $process->getErrorOutput());
        } finally {
            @unlink($tmp);
        }
    }

    private function runRector(string $rootDir, string $tmpDir): void
    {
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';
        Assert::true(is_file($rectorBin), 'rector binary not found at ' . $rectorBin);

        $process = new Process(
            [PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi'],
            $rootDir,
            timeout: 300.0,
        );
        $process->run();

        Assert::same(0, $process->getExitCode(), "rector failed.\nOUTPUT:\n{$process->getOutput()}\n{$process->getErrorOutput()}");
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

            @unlink($path);
        }

        @rmdir($dir);
    }
}
