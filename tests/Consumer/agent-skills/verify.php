<?php

declare(strict_types=1);

// Runs from the package root; installs an archive rather than a path symlink.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function write(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    check(file_put_contents($path, $contents) !== false, 'Cannot write ' . $path);
}

function execute(array $command, string $directory, int $expected = 0, int $timeout = 180, bool $stream = false): string
{
    $process = new Process($command, $directory, [
        'PATH' => dirname(PHP_BINARY) . PATH_SEPARATOR . getenv('PATH'),
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'COMPOSER' => false,
        'COMPOSER_NO_DEV' => false,
    ], timeout: $timeout);
    try {
        $exit = $process->run($stream ? static function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        } : null);
    } catch (ProcessTimedOutException $failure) {
        throw new RuntimeException('Timed out after ' . $timeout . ' seconds: ' . implode(' ', $command)
            . "\n" . substr($process->getOutput() . $process->getErrorOutput(), -16000), previous: $failure);
    }
    check($exit === $expected, 'Unexpected exit ' . $exit . ' from ' . implode(' ', $command)
        . "\n" . $process->getOutput() . $process->getErrorOutput());
    return $process->getOutput();
}

/** @return list<string> */
function phpExamples(string $file): array
{
    preg_match_all('/```php\R(.*?)\R```/s', file_get_contents($file), $matches);
    check($matches[1] !== [], 'No PHP examples in ' . $file);
    return array_map(static fn(string $code): string => $code . "\n", $matches[1]);
}

/** @return array<string, string> */
function hashes(string $directory): array
{
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $hashes[str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1))] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($hashes);
    return $hashes;
}

$root = dirname(__DIR__, 3);
foreach (['zip', 'pdo_sqlite', 'SimpleXML'] as $extension) {
    check(extension_loaded($extension), 'Consumer verification requires PHP extension: ' . $extension);
}
$updateLock = in_array('--update-lock', $argv, true);
$arguments = array_values(array_filter(array_slice($argv, 1), static fn(string $argument): bool => $argument !== '--update-lock'));
$composerBinary = $arguments[0] ?? (new ExecutableFinder())->find('composer');
check(is_string($composerBinary) && $composerBinary !== '', 'Composer executable not found; pass its PHAR path as the first argument.');
$composer = str_ends_with($composerBinary, '.phar') ? [PHP_BINARY, $composerBinary] : [$composerBinary];
$owned = sys_get_temp_dir() . '/laratesto-skills-' . bin2hex(random_bytes(8));
mkdir($owned . '/archive', 0777, true);
mkdir($owned . '/source');
mkdir($owned . '/consumer');
$consumer = $owned . '/consumer';
try {
    // Archive a source snapshot: ignored runtime files and Windows vendor
    // junctions must not affect packaging. Include uncommitted skill additions
    // while developing; tracked source files are read from the current worktree.
    $source = $owned . '/source';
    $tracked = execute(['git', 'ls-files', '--cached', '-z'], $root);
    foreach (array_filter(explode("\0", $tracked)) as $relative) {
        check(is_file($root . '/' . $relative), 'Missing tracked source: ' . $relative);
        write($source . '/' . $relative, file_get_contents($root . '/' . $relative));
    }
    write($source . '/.gitattributes', file_get_contents($root . '/.gitattributes'));
    (new Filesystem())->copyDirectory($root . '/skills', $source . '/skills');
    write($source . '/.ai-factory/not-for-distribution.txt', 'archive exclusion probe');
    execute([...$composer, 'archive', '--format=zip', '--dir=' . $owned . '/archive', '--file=laratesto', '--no-interaction'], $source);
    $archive = $owned . '/archive/laratesto.zip';
    check(is_file($archive), 'Missing Composer archive.');
    $package = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $package['version'] = '0.8.99'; // Local test version, never published.
    mkdir($consumer . '/archive');
    copy($archive, $consumer . '/archive/laratesto.zip');
    $package['dist'] = ['type' => 'zip', 'url' => 'archive/laratesto.zip'];
    write($consumer . '/composer.json', json_encode([
        'name' => 'laratesto-tests/agent-skills-consumer',
        'license' => 'BSD-3-Clause',
        'repositories' => [['type' => 'package', 'package' => $package]],
        'require-dev' => [
            'ichinya/laratesto' => '0.8.99', 'llm/skills' => '1.14.0',
            'testo/testo' => '0.10.56', 'laravel/framework' => '13.31.0',
        ],
        'config' => ['platform' => ['php' => '8.3.0'], 'allow-plugins' => ['llm/skills' => true]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    write($consumer . '/skills.json', json_encode([
        'target' => '.agents/skills', 'aliases' => [], 'auto-sync' => false,
        'dependencies' => ['composer' => ['trusted' => ['ichinya/laratesto', 'testo/testo']]],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $settings = file_get_contents($consumer . '/skills.json');
    $lock = __DIR__ . '/composer.lock';
    if ($updateLock) {
        execute([...$composer, 'update', '--no-install', '--no-interaction', '--no-progress'], $consumer, timeout: 600, stream: true);
        copy($consumer . '/composer.lock', $lock);
    } else {
        check(is_file($lock), 'Missing consumer lock; run this verifier with --update-lock first.');
        copy($lock, $consumer . '/composer.lock');
    }
    // Only the locally built distribution changes between runs. Keep every
    // resolved dependency fixed, but bind this temporary lock to the current
    // archive so Composer cannot reuse a stale synthetic 0.8.99 ZIP from cache.
    $locked = json_decode(file_get_contents($consumer . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($locked['packages-dev'] as &$lockedPackage) {
        if ($lockedPackage['name'] === 'ichinya/laratesto') {
            $lockedPackage['dist']['url'] = str_replace('\\', '/', $consumer . '/archive/laratesto.zip');
            $lockedPackage['dist']['shasum'] = sha1_file($archive);
        }
    }
    unset($lockedPackage);
    write($consumer . '/composer.lock', json_encode($locked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    execute([...$composer, 'validate', '--strict', '--no-check-publish'], $consumer);
    execute([...$composer, 'install', '--no-interaction', '--no-progress', '--prefer-dist'], $consumer, timeout: 600, stream: true);
    $donor = $consumer . '/vendor/ichinya/laratesto';
    check(is_dir($donor . '/src') && !is_dir($donor . '/tests') && !is_dir($donor . '/.ai-factory'), 'Unexpected archive contents.');
    check(hashes($root . '/skills') === hashes($donor . '/skills'), 'Archive lost or changed skill files.');
    $installed = json_decode(file_get_contents($donor . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    check(($installed['extra']['skills']['source'] ?? null) === 'skills', 'Archive metadata missing.');

    $discovery = execute([...$composer, 'skills:show', 'ichinya/laratesto', '--no-discovery', '--no-ansi'], $consumer);
    foreach (glob($donor . '/skills/*/SKILL.md') as $skill) {
        check(str_contains($discovery, basename(dirname($skill))), 'Declared donor was not discovered: ' . $skill);
    }
    execute([...$composer, 'skills:update', 'ichinya/laratesto', 'testo/testo', '--no-discovery', '--no-interaction'], $consumer);
    $synced = $consumer . '/.agents/skills';
    $copiedFiles = 0;
    foreach (['ichinya/laratesto', 'testo/testo'] as $name) {
        foreach (glob($consumer . '/vendor/' . $name . '/skills/*/SKILL.md') as $entry) {
            $directory = dirname($entry);
            $files = hashes($directory);
            check($files === hashes($synced . '/' . basename($directory)), 'Synced files differ: ' . $entry);
            $copiedFiles += count($files);
        }
    }
    check($settings === file_get_contents($consumer . '/skills.json'), 'Donor changed consumer agent settings.');

    // Run the code printed by the copied skills, rather than a parallel fixture.
    write($consumer . '/testo.php', phpExamples($synced . '/laratesto-configure/SKILL.md')[0]);
    $examples = phpExamples($synced . '/laratesto-write-tests/SKILL.md');
    check(count($examples) === 2, 'Unexpected write-tests example structure.');
    write($consumer . '/tests/Feature/BridgeSmokeTest.php', $examples[0]);
    write($consumer . '/tests/Feature/TraitSmokeTest.php', $examples[1]);
    write($consumer . '/database/migrations/2026_01_01_000000_create_skill_widgets.php',
        phpExamples($synced . '/laratesto-write-tests/references/database-migration.md')[0]);
    write($consumer . '/bootstrap/app.php', <<<'PHP'
<?php
use Illuminate\Foundation\Application;
return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([Laratesto\LaratestoServiceProvider::class])
    ->withExceptions(static function (Illuminate\Foundation\Configuration\Exceptions $exceptions): void {})
    ->create();
PHP);
    write($consumer . '/config/app.php', "<?php\nreturn ['name' => 'Skill consumer', 'key' => 'base64:" . base64_encode(str_repeat('a', 32)) . "', 'cipher' => 'AES-256-CBC'];\n");
    write($consumer . '/config/database.php', <<<'PHP'
<?php
return [
    'default' => 'sqlite',
    'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
PHP);
    mkdir($consumer . '/bootstrap/cache', 0777, true);
    mkdir($consumer . '/storage/framework/views', 0777, true);
    mkdir($consumer . '/storage/logs', 0777, true);
    execute([PHP_BINARY, 'vendor/bin/testo', 'run', '--suite=Laravel', '--log-json=report.json', '--log-junit=report.xml'], $consumer);
    $report = json_decode(file_get_contents($consumer . '/report.json'), true, flags: JSON_THROW_ON_ERROR);
    check($report['status'] === 'passed' && $report['totals']['total'] === 5
        && $report['totals']['passed'] === 5 && $report['totals']['assertions'] >= 8,
        'Documentation examples did not all execute successfully: ' . json_encode($report));
    $comparison = $synced . '/laratesto-migrate-from-phpunit/scripts/compare-outcomes.php';
    execute([PHP_BINARY, $comparison, 'report.xml', 'report.xml'], $consumer);
    echo json_encode(['laratestoSkills' => 3, 'testoSkills' => 14, 'copiedFiles' => $copiedFiles,
        'examples' => $report['totals'], 'archiveAndSync' => 'passed'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} finally {
    // Only remove the unique temporary directory created by this invocation.
    (new Filesystem())->deleteDirectory($owned);
}
