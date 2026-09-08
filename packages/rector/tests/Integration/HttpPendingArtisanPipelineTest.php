<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Testo\Assert;
use Testo\Test;

final class HttpPendingArtisanPipelineTest
{
    #[Test]
    public function laravelAssertionsDoNotReleaseRetainedCommands(): void
    {
        HttpPendingTimingProbe::$events = [];
        try {
            $pending = (new \ReflectionClass(HttpPendingTimingProbe::class))->newInstanceWithoutConstructor();
            $pending->assertExitCode(0);
            HttpPendingTimingProbe::$events[] = 'after assertion';
            unset($pending);
            Assert::same(['after assertion', 'run'], HttpPendingTimingProbe::$events);
        } finally {
            HttpPendingTimingProbe::$events = [];
        }
    }

    #[Test]
    public function retainedCommandsCannotHideTimingGapsInsideControlFlow(): void
    {
        $rootDir = \dirname(__DIR__, 4);
        $tmpDir = \sys_get_temp_dir() . '/laratesto-http-pending-' . \bin2hex(\random_bytes(8));
        Assert::true(\mkdir($tmpDir . '/corpus', 0777, true));
        $gap = '$pending = $this->artisan("cache:clear"); $observed = 1; $pending->assertExitCode(0);';
        $immediate = '$pending = $this->artisan("cache:clear"); $pending->assertExitCode(0);';
        $cases = [
            'NestedIf' => 'if (true) { ' . $gap . ' }',
            'NestedTry' => 'try { ' . $gap . ' } catch (\\Throwable $exception) {}',
            'Loop' => 'foreach ([1] as $item) { ' . $gap . ' }',
            'WhileLoop' => 'while (false) { ' . $gap . ' }',
            'ForLoop' => 'for ($i = 0; $i < 1; $i++) { ' . $gap . ' }',
            'Closure' => '$callback = function (): void { ' . $gap . ' }; $callback();',
            'DeepUse' => '$pending = $this->artisan("cache:clear"); if (false) { $pending->assertExitCode(0); } $observed = 1; $pending->assertExitCode(0);',
            'CaptureAlias' => '$outside = null; $callback = function () use (&$outside): void { $pending =& $outside; ' . $immediate . ' }; $callback(); $observed = 1;',
            'CaptureAliasChain' => '$outside = null; $callback = function () use (&$outside): void { $bridge =& $outside; $pending =& $bridge; ' . $immediate . ' }; $callback(); $observed = 1;',
            'StaticSlot' => 'static $pending; ' . $immediate,
            'GlobalSlot' => 'global $pending; ' . $immediate,
            'LocalReference' => '$pending = null; $alias =& $pending; ' . $immediate,
            'ClosureReference' => '$pending = null; $callback = function () use (&$pending): void { ' . $immediate . ' }; $callback(); $observed = 1;',
            'ReferenceParameter' => $immediate,
            'UnrelatedReference' => '$outside = null; $callback = function () use (&$outside): void { ' . $immediate . ' }; $callback();',
            'CapturedUse' => '$pending = $this->artisan("cache:clear"); $callback = function () use ($pending): void { $pending->assertExitCode(0); }; $observed = 1;',
            'ArrowUse' => '$pending = $this->artisan("cache:clear"); $callback = fn () => $pending->assertExitCode(0); $observed = 1;',
            'ConditionalUse' => '$pending = $this->artisan("cache:clear"); false && $pending->assertExitCode(0); $observed = 1;',
            'ParentArtisan' => '$pending = parent::artisan("cache:clear"); $observed = 1; $pending->assertExitCode(0);',
            'ArrowReturnsPending' => '$callback = fn () => $this->artisan("cache:clear"); $pending = $callback(); $observed = 1;',
            'AfterAssertion' => $immediate . ' $observed = 1;',
            'EscapingFluentResult' => '$pending = $this->artisan("cache:clear"); $alias = $pending->assertExitCode(0); $observed = 1;',
            'BlockedWithDeferred' => $gap . ' $this->expectOutputRegex("/x/");',
            'ExistingPendingHelper' => $gap,
            'OuterContinuation' => 'if (true) { ' . $immediate . ' } $observed = 1;',
            'FinallyContinuation' => 'try { ' . $immediate . ' } finally { $observed = 1; }',
            'RetainedLoop' => 'foreach ([1, 2] as $item) { ' . $immediate . ' }',
            'Immediate' => $immediate,
            'Inline' => '$this->artisan("cache:clear")->assertExitCode(0); $observed = 1;',
            'TerminalBranch' => 'if (true) { ' . $immediate . ' }',
            'TerminalClosure' => '$callback = function (): void { ' . $immediate . ' }; $callback();',
        ];
        $supported = ['Immediate', 'Inline', 'TerminalBranch', 'TerminalClosure', 'UnrelatedReference', 'LocalReference', 'AfterAssertion'];
        try {
            foreach ($cases as $name => $body) {
                \file_put_contents($tmpDir . '/corpus/' . $name . '.php', '<?php namespace HttpPending; final class '
                    . $name . ' extends \\Illuminate\\Foundation\\Testing\\TestCase { '
                    . ($name === 'ExistingPendingHelper' ? 'protected function pendingArtisan(string $command): int { return 99; } ' : '')
                    . 'public function testExample(' . ($name === 'ReferenceParameter' ? '&$pending' : '') . '): void { ' . $body . ' } }');
            }
            foreach (\glob($tmpDir . '/corpus/*.php') ?: [] as $file) {
                $this->assertValidPhp($file);
            }
            $this->runRector($rootDir, $tmpDir, [$tmpDir . '/corpus']);
            foreach ($cases as $name => $body) {
                $output = (string) \file_get_contents($tmpDir . '/corpus/' . $name . '.php');
                if (\in_array($name, $supported, true)) {
                    Assert::string($output)->notContains('laratesto-residual', $name);
                    Assert::string($output)->contains('extends \\Laratesto\\Testing\\LaravelTestCase', $name);
                } else {
                    Assert::string($output)->contains($name === 'BlockedWithDeferred' ? 'HTTP_UNSUPPORTED_SIGNATURE' : 'ARTISAN_INTERACTION_UNSUPPORTED', $name);
                    Assert::string($output)->notContains('extends \\Laratesto\\Testing\\LaravelTestCase', $name);
                    Assert::string($output)->notContains('$this->pendingArtisan', $name);
                }
                $this->assertValidPhp($tmpDir . '/corpus/' . $name . '.php');
            }
            $before = $this->snapshotDirectory($tmpDir . '/corpus');
            $this->runRector($rootDir, $tmpDir, [$tmpDir . '/corpus']);
            Assert::same($before, $this->snapshotDirectory($tmpDir . '/corpus'));
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    private function assertValidPhp(string $path): void
    {
        \exec('php -l ' . \escapeshellarg($path) . ' 2>&1', $out, $code);

        Assert::same(0, $code, "php -l failed for {$path}: " . \implode("\n", $out));
    }

    private function runRector(string $rootDir, string $tmpDir, array $paths): void
    {
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';
        Assert::true(\is_file($rectorBin), 'rector binary not found at ' . $rectorBin);

        $cacheExport = \var_export($tmpDir . '/rector-cache', true);
        $pathsExport = \var_export($paths, true);
        $setExport = \var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
        \file_put_contents($tmpDir . '/rector.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Rector\Config\RectorConfig;

            return RectorConfig::configure()
                ->withCache(cacheDirectory: {$cacheExport})
                ->withPaths({$pathsExport})
                ->withSets([{$setExport}]);
            PHP);

        $process = \proc_open(
            [\PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $rootDir,
        );
        Assert::true(\is_resource($process), 'proc_open failed');
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $exitCode = \proc_close($process);

        Assert::same(0, $exitCode, "rector failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
    }

    /**
     * @return array<string, string>
     */
    private function snapshotDirectory(string $directory): array
    {
        $hashes = [];

        foreach (\glob($directory . '/*.php') ?: [] as $file) {
            $hashes[$file] = (string) \file_get_contents($file);
        }

        \ksort($hashes);

        return $hashes;
    }

    private static function recursiveRemove(string $dir): void
    {
        if (! \is_dir($dir)) {
            return;
        }

        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            \is_dir($path) ? self::recursiveRemove($path) : @\unlink($path);
        }

        @\rmdir($dir);
    }
}

final class HttpPendingTimingProbe extends \Illuminate\Testing\PendingCommand
{
    public static array $events = [];

    public function run(): int
    {
        $this->hasExecuted = true;
        self::$events[] = 'run';
        return 0;
    }
}
