<?php

declare(strict_types=1);

$directory = getenv('LARATESTO_PBT_DIRECTORY');
if ($directory === false || !is_dir($directory)) {
    throw new RuntimeException('Run this bounded consumer through verify.php.');
}
$coverage = getenv('LARATESTO_PBT_COVERAGE') === '1';
return new \Testo\Application\Config\ApplicationConfig(
    src: [__DIR__.'/tests/ProbeMath.php'],
    plugins: $coverage ? [new \Testo\Codecov\CodecovPlugin(
        level: \Testo\Codecov\Config\CoverageLevel::Line,
        collect: \Testo\Codecov\Config\CoverageMode::Always,
        reports: [new \Testo\Codecov\Report\CloverReport($directory.'/coverage.xml')],
    )] : [],
    suites: [new \Testo\Application\Config\SuiteConfig(name: 'PropertyConsumer', location: [__DIR__.'/tests'], plugins: [
        new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(basePath: dirname(__DIR__, 2).'/Fixture/laravel')),
    ])],
);
