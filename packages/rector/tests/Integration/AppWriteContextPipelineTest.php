<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * PR8 review finding #12: every `$this->app` property fetch was rewritten to an
 * `app()` method call - including write contexts. `isset($this->app())`,
 * `unset($this->app())`, `$this->app() = ...` and friends do not compile
 * ("Cannot use isset() on the result of an expression" / "Can't use method
 * return value in write context"), so the migrated test died at parse time with
 * no residual. Rector still reported success.
 *
 * The conversion now classifies the expression context of each `$this->app`
 * fetch and fails closed with HTTP_UNSUPPORTED_SIGNATURE for write contexts
 * (assignment, reference binding, isset/unset, by-reference parameters),
 * while ordinary reads and member accesses that keep compiling keep converting.
 */
final class AppWriteContextPipelineTest
{
    #[Test]
    public function appWriteContextsFailClosedAndReadsStillConvert(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-app-write-' . getmypid();
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true) || is_dir($tmpDir . '/corpus'));

        try {
            file_put_contents($tmpDir . '/corpus/Tests.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class AppAssignTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app = app();
    }

    public function test_ok(): void {}
}

final class AppIssetUnsetTest extends TestCase
{
    public function test_checks(): void
    {
        if (isset($this->app)) {
            unset($this->app);
        }
    }
}

final class ControlReadTest extends TestCase
{
    public function test_reads(): void
    {
        $booted = isset($this->app->booted);
        $empty = empty($this->app);
        $config = $this->app['config']->get('x');
        $this->app->flag = true;
        $made = $this->app->make('cache');
        $snapshot = [$this->app];
    }
}
PHP);

            $this->writeConfig($tmpDir);

            $this->runRector($rootDir, $tmpDir);

            $tests = (string) file_get_contents($tmpDir . '/corpus/Tests.php');

            // The write-context classes fail closed: preserved hierarchy, raw
            // writes kept, marker demands manual migration. The marker sits above
            // each class declaration, so its reason is asserted file-wide.
            Assert::string($tests)->contains('$this->app is used in a write context');
            Assert::string($tests)->contains('laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE');

            $assignBlock = self::between($tests, 'final class AppAssignTest', 'final class AppIssetUnsetTest');
            Assert::string($assignBlock)->contains('$this->app = app();');
            Assert::string($assignBlock)->contains('extends TestCase');
            Assert::string($assignBlock)->notContains('$this->app()');
            Assert::string($assignBlock)->notContains('LaravelTestCase');

            $issetUnsetBlock = self::between($tests, 'final class AppIssetUnsetTest', 'final class ControlReadTest');
            Assert::string($issetUnsetBlock)->contains('if (isset($this->app))');
            Assert::string($issetUnsetBlock)->contains('unset($this->app);');
            Assert::string($issetUnsetBlock)->notContains('app() {');

            // Control: ordinary reads and member writes keep converting into
            // compiling app() expressions; an array literal holding the app
            // ($snapshot = [$this->app]) is a by-value read and stays too.
            $controlBlock = self::between($tests, 'final class ControlReadTest', "\0");
            Assert::string($controlBlock)->notContains('laratesto-residual');
            Assert::string($controlBlock)->contains('extends \Laratesto\Testing\LaravelTestCase');
            Assert::string($controlBlock)->contains('isset($this->app()->booted);');
            Assert::string($controlBlock)->contains('empty($this->app());');
            Assert::string($controlBlock)->contains('$this->app()[\'config\']->get(\'x\');');
            Assert::string($controlBlock)->contains('$this->app()->flag = true;');
            Assert::string($controlBlock)->contains('$this->make(\'cache\');');
            Assert::string($controlBlock)->contains('[$this->app()];');

            // Every output file must be syntactically valid PHP.
            self::assertCompiles($tests);

            // Second run must not touch anything.
            $snapshot = $tests;
            $this->runRector($rootDir, $tmpDir);
            Assert::same($snapshot, (string) file_get_contents($tmpDir . '/corpus/Tests.php'), 'Second run modified Tests.php.');
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    #[Test]
    public function referencesAndForeachTargetsPreserveWritableProperties(): void
    {
        $rootDir = dirname(__DIR__, 4);
        $tmpDir = sys_get_temp_dir() . '/laratesto-rector-app-references-' . bin2hex(random_bytes(8));
        Assert::true(mkdir($tmpDir . '/corpus', 0777, true));
        $cases = [
            'FunctionReference' => ['function takeRef(&$container): void {}', 'takeRef($this->app);', false],
            'NamedReference' => ['function takeRef($unused = null, &$container = null): void {}', 'takeRef(container: $this->app);', false],
            'ConstructorReference' => ['class Holder { public function __construct(&$container) {} }', '$holder = new Holder($this->app);', false],
            'VariadicReference' => ['function takeRefs(&...$values): void {}', '$other = null; takeRefs($other, $this->app);', false],
            'NamedVariadicReference' => ['function takeRefs(&...$values): void {}', 'takeRefs(container: $this->app);', false],
            'ReferenceArray' => ['', '$references = [&$this->app];', false],
            'ForeachTarget' => ['', 'foreach ([$this->app] as $this->app) {}', false],
            'FunctionValue' => ['function takeRef($container): void {}', 'takeRef($this->app);', true],
        ];

        try {
            foreach ($cases as $name => [$helper, $body]) {
                $source = "<?php\nnamespace AppWrite\\{$name};\n{$helper}\n"
                    . "final class {$name} extends \\Illuminate\\Foundation\\Testing\\TestCase {\n"
                    . "    public function test_example(): void { {$body} }\n}\n";
                self::assertCompiles($source);
                file_put_contents($tmpDir . '/corpus/' . $name . '.php', $source);
            }

            $this->writeConfig($tmpDir);
            $this->runRector($rootDir, $tmpDir);
            $snapshot = [];

            foreach ($cases as $name => [, , $converts]) {
                $output = (string) file_get_contents($tmpDir . '/corpus/' . $name . '.php');
                $snapshot[$name] = $output;
                self::assertCompiles($output);

                if ($converts) {
                    Assert::string($output)->notContains('laratesto-residual');
                    Assert::string($output)->contains('extends \\Laratesto\\Testing\\LaravelTestCase');
                    Assert::string($output)->contains('takeRef($this->app());');
                } else {
                    Assert::string($output)->contains('HTTP_UNSUPPORTED_SIGNATURE');
                    Assert::string($output)->contains('extends \\Illuminate\\Foundation\\Testing\\TestCase');
                    Assert::string($output)->notContains('$this->app()');
                }
            }

            $this->runRector($rootDir, $tmpDir);
            foreach ($snapshot as $name => $before) {
                Assert::same($before, (string) file_get_contents($tmpDir . '/corpus/' . $name . '.php'));
            }
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    private function writeConfig(string $tmpDir): void
    {
        $paths = var_export($tmpDir . '/corpus', true);
        $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
        $cache = var_export($tmpDir . '/rector-cache', true);
        file_put_contents($tmpDir . '/rector.php', <<<PHP
<?php

use Rector\\Config\\RectorConfig;

return RectorConfig::configure()
    ->withPaths([{$paths}])
    ->withSets([{$set}])
    ->withCache(cacheDirectory: {$cache});
PHP);
    }

    private static function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        Assert::true($start !== false, 'Missing expected block start ' . $from);

        $end = strpos($haystack, $to, $start);

        return substr($haystack, $start, $end === false ? null : $end - $start);
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
            [PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'],
            $rootDir,
            timeout: 300.0,
        );
        $process->run();

        Assert::same(0, $process->getExitCode(), "rector failed.\nOUTPUT:\n{$process->getOutput()}\n{$process->getErrorOutput()}");
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
