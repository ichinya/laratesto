<?php

declare(strict_types=1);

namespace Laratesto\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Forces a native Testo child to die and observes its database through fresh PDOs. */
final class CommitBoundaryRunner
{
    /** @return array{checkpoint: array, exit: int, visibleBeforeKill: int, durableAfterKill: int, writableAfterKill: bool, shutdownRan: bool} */
    public static function run(string $phase): array
    {
        if (!in_array($phase, ['before', 'after'], true)) {
            throw new \InvalidArgumentException('Unknown commit boundary.');
        }
        $root = dirname(__DIR__, 2);
        $directory = $root.'/storage/framework/testing/commit-boundary-'.bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);
        $database = $directory.'/database.sqlite';
        $checkpointPath = $directory.'/checkpoint.json';
        $process = new Process([
            PHP_BINARY, $root.'/vendor/bin/testo', 'run', '--config', $root.'/tests/Fixture/commit-boundary/testo.php', '--json',
        ], $root, [
            'LARATESTO_CRASH_DATABASE' => $database,
            'LARATESTO_CRASH_CHECKPOINT' => $checkpointPath,
            'LARATESTO_CRASH_PHASE' => $phase,
        ], timeout: 45);

        try {
            $observer = self::connect($database);
            $observer->exec('CREATE TABLE commit_probe (id INTEGER PRIMARY KEY)');
            $observer = null;
            $process->start();
            $deadline = hrtime(true) + 30_000_000_000;
            while (!is_file($checkpointPath)) {
                if (!$process->isRunning()) {
                    throw new \RuntimeException('Child exited before the commit checkpoint.');
                }
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('Timed out waiting for the commit checkpoint.');
                }
                usleep(10_000);
                clearstatcache(true, $checkpointPath);
            }
            $checkpoint = json_decode(file_get_contents($checkpointPath), true, flags: JSON_THROW_ON_ERROR);
            $observer = self::connect($database);
            $visibleBeforeKill = (int) $observer->query('SELECT COUNT(*) FROM commit_probe')->fetchColumn();
            $observer = null;

            // SIGKILL on POSIX; Symfony uses taskkill /F /T on Windows. A normal
            // PHP exit would run shutdown callbacks and cannot test this boundary.
            $process->signal(9);
            $exit = $process->wait();

            $observer = self::connect($database);
            $durableAfterKill = (int) $observer->query('SELECT COUNT(*) FROM commit_probe')->fetchColumn();
            // The dead child must not keep its write lock or transaction alive.
            $observer->exec('INSERT INTO commit_probe (id) VALUES (20)');
            $writableAfterKill = (int) $observer->query('SELECT COUNT(*) FROM commit_probe')->fetchColumn() === $durableAfterKill + 1;
            $observer = null;
            $shutdownRan = is_file($checkpointPath.'.shutdown');
            return compact('checkpoint', 'exit', 'visibleBeforeKill', 'durableAfterKill', 'writableAfterKill', 'shutdownRan');
        } catch (\Throwable $failure) {
            throw new \RuntimeException('Commit boundary '.$phase.': '.$failure->getMessage().PHP_EOL
                .$process->getOutput().$process->getErrorOutput(), previous: $failure);
        } finally {
            $process->isRunning() && $process->stop(0, 9);
            $observer = null;
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private static function connect(string $database): \PDO
    {
        $pdo = new \PDO('sqlite:'.$database, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 1000');
        return $pdo;
    }
}
