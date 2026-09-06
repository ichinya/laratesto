<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Testo\Assert;
use Testo\Test;

final class HttpResponseConstructionPipelineTest
{
    #[Test]
    public function factoriesAndUnknownConstructionFailClosedWhileResponseValuesConvert(): void
    {
        $rootDir = \dirname(__DIR__, 4);
        $tmpDir = \sys_get_temp_dir() . '/laratesto-http-construction-' . \bin2hex(\random_bytes(8));
        Assert::true(\mkdir($tmpDir . '/corpus', 0777, true));
        $cases = [
            'Factory' => 'public function example(): TestResponse { return TestResponse::fromBaseResponse(new Response("ok")); }',
            'DynamicFactory' => 'public function example(): TestResponse { $factory = "fromBaseResponse"; return TestResponse::$factory(new Response("ok")); }',
            'Unknown' => 'public function example($value): TestResponse { return new TestResponse($value); }',
            'OtherObject' => 'public function example(): TestResponse { return new TestResponse(new \\stdClass()); }',
            'RequestArgument' => 'public function example(): TestResponse { return new TestResponse(new Response("ok"), new \\Illuminate\\Http\\Request()); }',
            'Nullable' => 'public function example(?Response $value): TestResponse { return new TestResponse($value); }',
            'Direct' => 'public function example(): TestResponse { return new TestResponse(new Response("ok")); }',
            'Variable' => 'public function example(): TestResponse { $value = new Response("ok"); return new TestResponse($value); }',
            'Typed' => 'public function example(Response $value): TestResponse { return new TestResponse($value); }',
            'Named' => 'public function example(): TestResponse { return new TestResponse(response: new Response(content: "ok")); }',
            'Laravel' => 'public function example(): TestResponse { return new TestResponse(new \\Illuminate\\Http\\Response("ok")); }',
            'Json' => 'public function example(): TestResponse { return new TestResponse(new \\Symfony\\Component\\HttpFoundation\\JsonResponse(["ok"])); }',
        ];
        $blocked = ['Factory', 'DynamicFactory', 'Unknown', 'OtherObject', 'RequestArgument', 'Nullable'];
        try {
            foreach ($cases as $name => $method) {
                \file_put_contents($tmpDir . '/corpus/' . $name . '.php', '<?php namespace HttpConstruction; '
                    . 'use Illuminate\\Testing\\TestResponse; use Symfony\\Component\\HttpFoundation\\Response; '
                    . 'final class ' . $name . ' extends \\Illuminate\\Foundation\\Testing\\TestCase { ' . $method . ' }');
            }
            // One blocked class prevents the shared response import/type swap for
            // its otherwise compatible sibling; an independent file still converts.
            \file_put_contents($tmpDir . '/corpus/Mixed.php', '<?php namespace HttpConstruction; '
                . 'use Illuminate\\Testing\\TestResponse; use Symfony\\Component\\HttpFoundation\\Response; '
                . 'class MixedBlocked extends \\Illuminate\\Foundation\\Testing\\TestCase { ' . $cases['Factory'] . ' } '
                . 'class MixedSafe extends \\Illuminate\\Foundation\\Testing\\TestCase { ' . $cases['Direct'] . ' }');
            foreach (\glob($tmpDir . '/corpus/*.php') ?: [] as $file) {
                $this->assertValidPhp($file);
            }
            $this->runRector($rootDir, $tmpDir, [$tmpDir . '/corpus']);
            foreach ($cases as $name => $method) {
                $output = (string) \file_get_contents($tmpDir . '/corpus/' . $name . '.php');
                if (\in_array($name, $blocked, true)) {
                    Assert::string($output)->contains('RESPONSE_UNSUPPORTED_API', $name);
                    Assert::string($output)->notContains('extends \\Laratesto\\Testing\\LaravelTestCase', $name);
                    Assert::string($output)->contains(': TestResponse', $name);
                } else {
                    Assert::string($output)->notContains('laratesto-residual', $name);
                    Assert::string($output)->contains('new \\Laratesto\\Testing\\LaravelResponse', $name);
                    Assert::string($output)->contains(': \\Laratesto\\Testing\\LaravelResponse', $name);
                }
            }
            $mixed = (string) \file_get_contents($tmpDir . '/corpus/Mixed.php');
            Assert::string($mixed)->contains('RESPONSE_UNSUPPORTED_API');
            Assert::string($mixed)->contains('TestResponse::fromBaseResponse');
            Assert::string($mixed)->notContains('new \\Laratesto\\Testing\\LaravelResponse');
            foreach (\glob($tmpDir . '/corpus/*.php') ?: [] as $file) {
                $this->assertValidPhp($file);
            }
            $probe = '<?php require ' . \var_export($rootDir . '/vendor/autoload.php', true) . ';';
            foreach (['Direct', 'Variable', 'Typed', 'Named', 'Laravel', 'Json'] as $name) {
                $probe .= 'require ' . \var_export($tmpDir . '/corpus/' . $name . '.php', true) . ';';
                $arguments = $name === 'Typed' ? 'new \\Symfony\\Component\\HttpFoundation\\Response("ok")' : '';
                $probe .= '$response = (new \\HttpConstruction\\' . $name . ')->example(' . $arguments . ');'
                    . 'if (!$response instanceof \\Laratesto\\Testing\\LaravelResponse || $response->status() !== 200) { exit(1); }';
            }
            \file_put_contents($tmpDir . '/runtime.php', $probe);
            $process = new \Symfony\Component\Process\Process([\PHP_BINARY, $tmpDir . '/runtime.php'], $rootDir);
            $process->run();
            Assert::same(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
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
