<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Testo\Assert;
use Testo\Test;

final class HttpDynamicSignaturesPipelineTest
{
    #[Test]
    public function dynamicMethodNamesCannotMasqueradeAsSupportedIdentifiers(): void
    {
        $rootDir = \dirname(__DIR__, 4);
        $tmpDir = \sys_get_temp_dir() . '/laratesto-http-dynamic-' . \bin2hex(\random_bytes(8));
        Assert::true(\mkdir($tmpDir . '/corpus', 0777, true));
        $fixtures = [
            'dynamic_this_helper_fail_closed',
            'dynamic_response_helper_fail_closed',
            'dynamic_artisan_helper_fail_closed',
            'dynamic_self_static_fail_closed',
            'call_arity_fail_closed',
            'call_named_unpack_fail_closed',
            'call_dynamic_shape_fail_closed',
            'call_common_signature_supported',
        ];
        try {
            foreach ($fixtures as $fixture) {
                $pair = (string) \file_get_contents($rootDir . '/packages/rector/src/Rules/LaravelSourceCompatibleCallsRector/' . $fixture . '.php.inc');
                $input = \preg_split('/\R-----\R/', $pair, 2)[0];
                \file_put_contents($tmpDir . '/corpus/' . $fixture . '.php', $input);
                $this->assertValidPhp($tmpDir . '/corpus/' . $fixture . '.php');
            }
            $this->runRector($rootDir, $tmpDir, [$tmpDir . '/corpus']);
            foreach ($fixtures as $fixture) {
                $output = (string) \file_get_contents($tmpDir . '/corpus/' . $fixture . '.php');
                if ($fixture === 'call_common_signature_supported') {
                    Assert::string($output)->notContains('laratesto-residual');
                    Assert::string($output)->contains('extends \\Laratesto\\Testing\\LaravelTestCase');
                } else {
                    Assert::string($output)->contains('HTTP_UNSUPPORTED_SIGNATURE', $fixture);
                    Assert::string($output)->notContains('extends \\Laratesto\\Testing\\LaravelTestCase', $fixture);
                }
                $this->assertValidPhp($tmpDir . '/corpus/' . $fixture . '.php');
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
