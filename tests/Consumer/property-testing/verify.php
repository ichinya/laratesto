<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function execute(string $directory, array $arguments = [], array $environment = []): array
{
    mkdir($directory, 0777, true);
    $process = new Process([PHP_BINARY, __DIR__.'/vendor/bin/testo', 'run', '--config='.__DIR__.'/testo.php',
        '--log-json='.$directory.'/report.json', '--log-junit='.$directory.'/report.xml', ...$arguments,
    ], __DIR__, [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'LARATESTO_PBT_DIRECTORY' => $directory, 'PROPERTY_DB' => $directory.'/corpus',
        'PROPERTY_SEED' => '7382910', 'PROPERTY_PATH' => false, 'PROPERTY_RUNS' => false,
        'PROPERTY_PHASES' => false, 'PROPERTY_EXHAUSTIVE' => false, 'PROPERTY_SEARCH_RUNS' => false,
        'LARATESTO_PBT_COVERAGE' => '0', ...$environment,
    ], timeout: 90);
    $exit = $process->run();
    check(is_file($directory.'/report.json') && is_file($directory.'/report.xml'), 'Missing real consumer reports: '.$process->getOutput().$process->getErrorOutput());
    $report = json_decode(file_get_contents($directory.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $junit = simplexml_load_file($directory.'/report.xml');
    check(count($junit->xpath('//testcase')) === $report['totals']['total'], 'Native JUnit case count mismatch.');
    check((int) $junit['assertions'] === $report['totals']['assertions'], 'Native JSON/JUnit assertion mismatch.');
    $events = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($directory.'/events.jsonl', FILE_IGNORE_NEW_LINES));
    return ['exit' => $exit, 'report' => $report, 'events' => $events, 'stderr' => $process->getErrorOutput()];
}

$owned = __DIR__.'/runtime/acceptance-'.bin2hex(random_bytes(8));
try {
    $run = execute($owned.'/initial');
    check($run['exit'] === 1, 'Intentional property failure must propagate exit 1.');
    $totals = $run['report']['totals'];
    $failureDiagnostics = array_map(static fn(array $failure): string => substr($failure['message'] ?? '', 0, 2000), $run['report']['failures'] ?? []);
    check(array_diff_key($totals, ['assertions' => true]) === ['total' => 5, 'passed' => 3, 'failed' => 1, 'skipped' => 1], 'Unexpected property statuses: '.json_encode([$totals, $failureDiagnostics], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    $counts = array_count_values(array_column($run['events'], 'event'));
    check($counts['setup'] === 5 && $counts['teardown'] === 5 && $counts['ordinary'] === 1, 'Laravel hook/application count mismatch.');
    check(count(array_unique(array_column(array_filter($run['events'], static fn(array $event): bool => $event['event'] === 'setup'), 'owner'))) === 5, 'Properties and following ordinary test must have distinct applications.');
    $trials = array_values(array_filter($run['events'], static fn(array $event): bool => $event['event'] === 'trial'));
    $expectedAssertions = 5;
    $weights = ['explicitGeneratorAndAutoBounds' => 6, 'sharedStateIsObserved' => 2, 'failingProperty' => 5, 'allTrialsSkip' => 4];
    foreach ($trials as $trial) {
        check($trial['owner'] === $trial['facadeOwner'], 'Facade changed owner during a property trial.');
        $expectedAssertions += $weights[$trial['property']];
    }
    check($totals['assertions'] === $expectedAssertions, 'Assertions must include every generated/shrink trial: '.json_encode([$totals, $expectedAssertions]));
    $shared = array_values(array_filter($trials, static fn(array $event): bool => $event['property'] === 'sharedStateIsObserved'));
    check(count($shared) === 7 && $shared[0]['rows'] === 0 && $shared[1]['rows'] === 1, 'The shared-state boundary must actually be observed.');
    check(!$shared[1]['guest'] && $shared[1]['container'] && $shared[1]['cache'] === true && $shared[1]['clock'], 'Generated trials must expose retained Laravel state.');
    check(count(array_unique(array_column($shared, 'owner'))) === 1, 'Do not claim fresh Laravel applications per trial.');
    $failure = $run['report']['failures'][0]['message'];
    check(str_contains($failure, 'Original:') && str_contains($failure, 'Shrunk:'), 'Missing original/shrunk counterexample.');
    check(preg_match('/seed=(\d+)/', $failure, $seed) === 1, 'Missing replay seed.');
    check(preg_match('/Path:\s*([^\r\n]*)/', $failure, $path) === 1, 'Missing shrink replay path.');
    $replay = execute($owned.'/replay', ['--filter=failingProperty'], ['PROPERTY_SEED' => $seed[1], 'PROPERTY_PATH' => trim($path[1])]);
    check($replay['exit'] === 1 && ($replay['report']['totals']['failed'] ?? 0) === 1, 'Recorded seed/path must reproduce the falsification.');
    check(preg_match('/Shrunk:\s*value=(\d+)/', $failure, $shrunk) === 1, 'Missing shrunk value.');
    check(preg_match('/Shrunk:\s*value=(\d+)/', $replay['report']['failures'][0]['message'], $replayed) === 1 && $shrunk[1] === $replayed[1], 'Seed/path replay changed the minimal counterexample.');
    $coverage = null;
    if (getenv('LARATESTO_PBT_REQUIRE_COVERAGE') === '1') {
        check(extension_loaded('xdebug') && in_array('coverage', xdebug_info('mode'), true), 'Consumer coverage requires active Xdebug.');
        $covered = execute($owned.'/coverage', ['--filter=sharedStateIsObserved'], ['LARATESTO_PBT_COVERAGE' => '1']);
        check($covered['exit'] === 0 && $covered['report']['totals']['passed'] === 1 && $covered['report']['totals']['assertions'] === 14, 'Coverage property did not execute all inputs.');
        $clover = simplexml_load_file($owned.'/coverage/coverage.xml');
        $hits = $clover->xpath('//file[contains(@name,"ProbeMath.php")]/line[@count > 0]');
        check(count($hits) >= 3, 'Aggregated real coverage must retain both example branches.');
        $coverage = count($hits);
    }
    echo json_encode(['php' => PHP_VERSION, 'testo' => Composer\InstalledVersions::getPrettyVersion('testo/testo'),
        'bridge' => Composer\InstalledVersions::getPrettyVersion('rasuvaeff/property-testing-testo'),
        'core' => Composer\InstalledVersions::getPrettyVersion('rasuvaeff/property-testing-core'),
        'laravel' => Illuminate\Foundation\Application::VERSION, 'totals' => $totals, 'trials' => count($trials),
        'seed' => $seed[1], 'path' => trim($path[1]), 'shrunk' => $shrunk[1], 'coverageHitLines' => $coverage,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} finally {
    (new Filesystem())->deleteDirectory($owned);
}
