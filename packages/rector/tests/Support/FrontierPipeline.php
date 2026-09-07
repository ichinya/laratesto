<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Support;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;

/** Fresh private cache for both public-set passes; compare unmodified raw bytes. */
final class FrontierPipeline
{
    /**
     * @param array<string, string> $files
     * @param array<mixed> $skip Paths are relative to the corpus, rule names are FQCNs.
     * @param list<string>|null $cliPaths Null configures paths; [] passes the corpus directory; a list passes files.
     * @return array<string, string>
     */
    public static function run(array $files, array $skip = [], ?array $cliPaths = null): array
    {
        $root = dirname(__DIR__, 4);
        $tmp = sys_get_temp_dir() . '/laratesto-frontier-' . bin2hex(random_bytes(8));
        Assert::true(mkdir($tmp . '/corpus', 0777, true));
        foreach ($files as $name => $bytes) {
            Assert::true(file_put_contents($tmp . '/corpus/' . $name, $bytes) !== false);
            self::process([PHP_BINARY, '-l', $tmp . '/corpus/' . $name], $root);
        }
        foreach ($skip as $rule => $paths) {
            if (is_array($paths)) {
                $skip[$rule] = array_map(static fn(string $path): string => $tmp . '/corpus/' . $path, $paths);
            } elseif (str_ends_with($paths, '.php')) {
                $skip[$rule] = $tmp . '/corpus/' . $paths;
            }
        }
        $paths = var_export($tmp . '/corpus', true);
        $cache = var_export($tmp . '/cache', true);
        $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
        $skipCode = var_export($skip, true);
        $configuredPaths = $cliPaths === null ? "->withPaths([{$paths}])->withAutoloadPaths([{$paths}])" : '';
        file_put_contents($tmp . '/rector.php', <<<PHP
            <?php
            return \Rector\Config\RectorConfig::configure()
                {$configuredPaths}
                ->withSets([{$set}])
                ->withSkip({$skipCode})
                ->withCache(cacheDirectory: {$cache})
                ->withoutParallel();
            PHP);
        $command = [PHP_BINARY, $root . '/vendor/rector/rector/bin/rector', 'process', '--config',
            $tmp . '/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'];
        if ($cliPaths !== null) {
            $command = [...$command, ...($cliPaths === [] ? [$tmp . '/corpus']
                : array_map(static fn(string $file): string => $tmp . '/corpus/' . $file, $cliPaths))];
        }
        self::process($command, $root);
        $snapshot = [];
        foreach ($files as $name => $_) {
            self::process([PHP_BINARY, '-l', $tmp . '/corpus/' . $name], $root);
            $snapshot[$name] = (string) file_get_contents($tmp . '/corpus/' . $name);
        }
        Assert::true($files !== $snapshot, 'The first public-set run must change the corpus.');
        self::process($command, $root);
        foreach ($snapshot as $name => $bytes) {
            Assert::same($bytes, file_get_contents($tmp . '/corpus/' . $name), $name . ' changed on the fresh-cache second pass');
        }

        return $snapshot;
    }

    /** @param list<string> $command */
    private static function process(array $command, string $root): void
    {
        $process = new Process($command, $root, timeout: 180.0);
        $process->run();
        Assert::same(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }
}
