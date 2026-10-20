<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$composer = getenv('COMPOSER_BINARY');
if ($composer === false || !is_file($composer)) {
    throw new RuntimeException('Set COMPOSER_BINARY to the Composer PHAR path.');
}
$directory = $root.'/storage/framework/testing/http-client-consumer-'.bin2hex(random_bytes(6));
mkdir($directory, 0777, true);
$fixture = $root.'/tests/Fixture/testo-compatibility/http-client/testo.php';
$package = [
    'name' => 'laratesto/http-client-consumer', 'minimum-stability' => 'dev', 'prefer-stable' => true,
    'require' => ['ichinya/laratesto' => '@dev', 'laravel/framework' => '13.31.0', 'testo/testo' => '0.10.56', 'phpunit/phpunit' => '^12.0'],
    'repositories' => [['type' => 'path', 'url' => str_replace('\\', '/', $root), 'options' => ['symlink' => true]]],
    'autoload' => ['psr-4' => ['App\\' => str_replace('\\', '/', $root).'/tests/Fixture/laravel/app/']],
    'config' => ['allow-plugins' => false],
];
file_put_contents($directory.'/composer.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$run = static function (string $name, array $command, array $environment = []) use ($directory): void {
    $process = new Process($command, $directory, $environment, timeout: 1200);
    $log = $directory.'/'.$name.'.log';
    file_put_contents($log, '');
    $exit = $process->run(static function (string $type, string $buffer) use ($log): void {
        file_put_contents($log, $buffer, FILE_APPEND);
    });
    if ($exit !== 0) {
        throw new RuntimeException($name.' failed: '.$process->getOutput().$process->getErrorOutput());
    }
};
echo 'Consumer: '.$directory."\n";
$run('install', [PHP_BINARY, $composer, 'install', '--no-interaction', '--no-progress']);
$run('phpunit', [PHP_BINARY, $directory.'/vendor/bin/phpunit', '--no-configuration', '--bootstrap='.$directory.'/vendor/autoload.php', __DIR__.'/RunnerSmokeTest.php']);
$report = $directory.'/testo.json';
$run('testo', [PHP_BINARY, $directory.'/vendor/bin/testo', 'run', '--config='.$fixture, '--no-ansi', '--log-json='.$report], ['LARATESTO_EXPECT_PHPUNIT' => '1']);
$result = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
if ($result['totals']['total'] !== 8 || $result['totals']['passed'] !== 7 || $result['totals']['flaky'] !== 1) {
    throw new RuntimeException('Unexpected native Testo consumer results.');
}
$lock = json_decode(file_get_contents($directory.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
$phpunit = array_values(array_filter($lock['packages'], static fn($package) => $package['name'] === 'phpunit/phpunit'))[0]['version'];
echo json_encode(['php' => PHP_VERSION, 'phpunit' => $phpunit, 'phpunitRunner' => 'passed', 'testo' => $result['totals'], 'evidence' => $directory], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
