<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Support;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;

final class DatabasePipeline
{
    /**
     * @param array<string, string> $files
     * @param list<string> $baseClasses
     * @return array<string, string>
     */
    public static function run(array $files, array $baseClasses = []): array
    {
        $root = dirname(__DIR__, 4);
        $tmp = sys_get_temp_dir() . '/laratesto-db-pipeline-' . bin2hex(random_bytes(8));
        Assert::true(mkdir($tmp . '/corpus', 0777, true));

        try {
            foreach ($files as $name => $source) {
                Assert::true(file_put_contents($tmp . '/corpus/' . $name, $source) !== false);
                self::process([PHP_BINARY, '-l', $tmp . '/corpus/' . $name], $root);
            }

            $paths = var_export($tmp . '/corpus', true);
            $cache = var_export($tmp . '/cache', true);
            $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            $bases = var_export(['Illuminate\Foundation\Testing\TestCase', ...$baseClasses], true);
            $baseRule = var_export(LaravelBaseClassRector::class, true);
            $baseKey = var_export(LaravelBaseClassRector::BASE_CLASSES, true);
            file_put_contents($tmp . '/rector.php', <<<PHP
                <?php
                return \Rector\Config\RectorConfig::configure()
                    ->withPaths([{$paths}])
                    ->withSets([{$set}])
                    ->withCache(cacheDirectory: {$cache})
                    ->withConfiguredRule({$baseRule}, [{$baseKey} => {$bases}]);
                PHP);

            $command = [PHP_BINARY, $root . '/vendor/rector/rector/bin/rector', 'process', '--config',
                $tmp . '/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'];
            self::process($command, $root);
            $snapshot = [];
            foreach ($files as $name => $_source) {
                self::process([PHP_BINARY, '-l', $tmp . '/corpus/' . $name], $root);
                $snapshot[$name] = file_get_contents($tmp . '/corpus/' . $name);
            }
            Assert::true($files !== $snapshot, 'The public set must transform the corpus.');

            // --clear-cache clears only the unique cache selected in this config.
            self::process($command, $root);
            foreach ($snapshot as $name => $bytes) {
                Assert::same($bytes, file_get_contents($tmp . '/corpus/' . $name), $name . ' changed on the fresh-cache second pass');
            }

            return $snapshot;
        } finally {
            self::remove($tmp);
        }
    }

    /** @param list<string> $command */
    private static function process(array $command, string $root): void
    {
        $process = new Process($command, $root, timeout: 300.0);
        $process->run();
        Assert::same(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    private static function remove(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
