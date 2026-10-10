<?php

declare(strict_types=1);

namespace Laratesto\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Runs isolated native fixtures against the selected installation, never a fake runner. */
final class TestoCompatibilityRunner
{
    /** @return array{exit: int, stdout: string, stderr: string, json: array, junit: \SimpleXMLElement, events: array, coverage: \SimpleXMLElement|null} */
    public static function run(string $fixture, array $environment = [], array $arguments = [], float $timeout = 120): array
    {
        $root = dirname(__DIR__, 2);
        $directory = $root.'/storage/framework/testing/compatibility-'.bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);
        $environment['LARATESTO_COMPAT_EVENTS'] = $directory.'/events.jsonl';
        $environment['LARATESTO_COMPAT_DIR'] = $directory;
        $process = new Process(
            [PHP_BINARY, $root.'/vendor/bin/testo', 'run', '--config', $root.'/tests/Fixture/testo-compatibility/'.$fixture.'/testo.php', '--json', '--log-junit='.$directory.'/junit.xml', ...$arguments],
            $root,
            $environment,
            timeout: $timeout,
        );
        try {
            $exit = $process->run();
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            $json = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($json) || !isset($json['totals']['total']) || !is_int($json['totals']['total']) || $json['totals']['total'] < 1) {
                throw new \RuntimeException('Missing or empty native Testo totals: '.$stdout.$stderr);
            }
            if (!is_file($directory.'/junit.xml')) {
                throw new \RuntimeException('Missing native JUnit report: '.$stdout.$stderr);
            }
            $junit = simplexml_load_file($directory.'/junit.xml');
            if ($junit === false || count($junit->xpath('//testcase')) === 0) {
                throw new \RuntimeException('Invalid or empty native JUnit report.');
            }
            $events = [];
            foreach (is_file($environment['LARATESTO_COMPAT_EVENTS']) ? file($environment['LARATESTO_COMPAT_EVENTS'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
                $events[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
            if (getenv('LARATESTO_DEBUG') === '1') {
                fwrite(STDERR, '[laratesto compatibility] '.$fixture.' exit='.$exit.' total='.$json['totals']['total'].PHP_EOL);
            }
            $coverage = is_file($directory.'/coverage.xml') ? simplexml_load_file($directory.'/coverage.xml') : null;
            return compact('exit', 'stdout', 'stderr', 'json', 'junit', 'events', 'coverage');
        } catch (\Throwable $failure) {
            throw new \RuntimeException('Testo compatibility fixture '.$fixture.' failed: '.$failure->getMessage().'\n'.substr($process->getOutput().$process->getErrorOutput(), -16000), previous: $failure);
        } finally {
            $process->isRunning() && $process->stop(1);
            (new Filesystem())->deleteDirectory($directory);
        }
    }
}
