<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/events.php';
$directory = getenv('LARATESTO_COMPAT_DIR');
$reports = [new \Testo\Codecov\Report\CloverReport($directory.'/coverage.xml')];
if (getenv('LARATESTO_COVERAGE_BAD_WRITE') === '1') {
    $reports[] = new \Testo\Codecov\Report\CloverReport($directory);
}
return new \Testo\Application\Config\ApplicationConfig(
    src: [__DIR__.'/src'],
    plugins: [new \Testo\Codecov\CodecovPlugin(level: \Testo\Codecov\Config\CoverageLevel::Line, collect: \Testo\Codecov\Config\CoverageMode::Always, reports: $reports)],
    suites: [new \Testo\Application\Config\SuiteConfig(name: 'Coverage', location: [__DIR__.'/cases'], plugins: [
        new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(basePath: dirname(__DIR__).'/app')),
    ])],
);
