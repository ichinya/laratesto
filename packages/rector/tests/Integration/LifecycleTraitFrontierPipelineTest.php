<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * PR8 review finding #2: `Class_::getMethods()` only sees methods declared in the
 * class itself, so a lifecycle/bootstrap override provided by a used trait sailed
 * through every gate. The class converted to the Laratesto base while the trait
 * kept its `setUp()` (calling `parent::setUp()`) - a guaranteed
 * `Call to undefined method LaravelTestCase::setUp()` at runtime with no residual.
 *
 * The conversion now resolves trait declarations (same file, other processed files
 * and vendor) and fails closed with LIFECYCLE_UNSUPPORTED when a trait provides
 * `setUp()`/`tearDown()` or cannot be resolved at all.
 *
 * Full public set over a real corpus, twice, byte-identical second run; every
 * output file must survive `php -l`.
 */
final class LifecycleTraitFrontierPipelineTest
{
    #[Test]
    public function traitLifecycleFailsClosedAndSafeTraitsStillConvert(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-trait-lifecycle-' . getmypid();
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        try {
            // Named so it sorts before BTests.php: the same-run cross-file trait is
            // parsed before its consumer, matching the established corpus ordering.
            file_put_contents($tmpDir . '/corpus/ASupport.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Support;

trait CrossFileBooting
{
    protected function setUp(): void
    {
        parent::setUp();
    }
}
PHP);
            file_put_contents($tmpDir . '/corpus/BTests.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
}

namespace Tests\Feature;

use Tests\Support\CrossFileBooting;

trait MixedBooting
{
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}

trait ClockHelper
{
    public function clock(): int
    {
        return 1;
    }
}

final class MixedTraitTest extends \Tests\TestCase
{
    use MixedBooting;

    public function test_ok(): void {}
}

final class CrossFileTraitTest extends \Tests\TestCase
{
    use CrossFileBooting;

    public function test_ok(): void {}
}

final class SafeTraitTest extends \Tests\TestCase
{
    use ClockHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache');
    }

    public function test_ok(): void {}
}
PHP);

            $paths = var_export($tmpDir . '/corpus', true);
            $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            file_put_contents($tmpDir . '/rector.php', <<<PHP
<?php

declare(strict_types=1);

use Rector\\Config\\RectorConfig;

return RectorConfig::configure()
    ->withPaths([{$paths}])
    ->withSets([{$set}]);
PHP);

            $this->runRector($rootDir, $tmpDir);

            $support = (string) file_get_contents($tmpDir . '/corpus/ASupport.php');
            $tests = (string) file_get_contents($tmpDir . '/corpus/BTests.php');

            // Each window starts at the marker reason (the marker itself sits above
            // the class declaration) and ends at the next class, so every assertion
            // below is provably about the right class.
            $mixedBlock = self::between($tests, 'trait Tests\Feature\MixedBooting provides tearDown()', 'final class CrossFileTraitTest');
            $crossFileBlock = self::between($tests, 'trait Tests\Support\CrossFileBooting provides setUp()', 'final class SafeTraitTest');
            $safeBlock = self::between($tests, 'final class SafeTraitTest', "\0");

            // The mixed-file trait lifecycle blocks its consumer: preserved class,
            // residual marker, no rename in the block. The upstream
            // LifecycleMethodToTestoRector still decorates the trait's tearDown with
            // the AfterTest attribute - harmless here: the preserved class stays on
            // the PHPUnit hierarchy where its parent::tearDown() keeps resolving,
            // and the marker demands the manual migration.
            Assert::string($mixedBlock)->contains('laratesto-residual(code=LIFECYCLE_UNSUPPORTED');
            Assert::string($mixedBlock)->contains('use MixedBooting;');
            Assert::string($mixedBlock)->notContains('tearDownLaravel');
            Assert::string($mixedBlock)->notContains('extends \Laratesto\Testing\LaravelTestCase');

            // The cross-file trait (resolved from another processed file) blocks too.
            Assert::string($crossFileBlock)->contains('use CrossFileBooting;');
            Assert::string($crossFileBlock)->notContains('setUpLaravel');

            // The untouched trait provider file keeps its lifecycle declaration and
            // gains no Laratesto conversion.
            Assert::string($support)->notContains('Laratesto');
            Assert::string($support)->contains('protected function setUp(): void');
            Assert::string($tests)->contains('abstract class TestCase extends \Laratesto\Testing\LaravelTestCase');
            // Control: a trait without lifecycle/bootstrap declarations does not
            // block - the safe class converts fully.
            Assert::string($safeBlock)->notContains('laratesto-residual');
            Assert::string($safeBlock)->contains('extends \Tests\TestCase');
            Assert::string($safeBlock)->contains('setUpLaravel');
            Assert::string($safeBlock)->contains('#[\Testo\Test]');
            Assert::string($safeBlock)->contains('$this->make(\'cache\');');

            // Every output file must be syntactically valid PHP.
            self::assertCompiles($support);
            self::assertCompiles($tests);

            // Second run must not touch anything.
            $snapshot = [
                'ASupport.php' => $support,
                'BTests.php' => $tests,
            ];
            $this->runRector($rootDir, $tmpDir);
            Assert::same($snapshot['ASupport.php'], (string) file_get_contents($tmpDir . '/corpus/ASupport.php'), 'Second run modified ASupport.php.');
            Assert::same($snapshot['BTests.php'], (string) file_get_contents($tmpDir . '/corpus/BTests.php'), 'Second run modified BTests.php.');
        } finally {
            self::recursiveRemove($tmpDir);
        }
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

        // argv-array invocation through Symfony Process: the command never reaches a
        // shell, and the process reads both pipes concurrently - a huge stderr trace
        // cannot deadlock the run. Finite timeout so a hung binary fails the test
        // instead of stalling it forever.
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
