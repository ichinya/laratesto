<?php

declare(strict_types=1);

use Laratesto\Rector\Configuration\AutoloadPaths;
use Laratesto\Rector\Configuration\ConfiguredHierarchy;
use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Rules\LaravelDatabaseTraitsRector;
use Laratesto\Rector\Rules\LaravelResidualDetectionRector;
use Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector;
use Laratesto\Rector\Rules\PhpUnitCompatibilityRector;
use Laratesto\Rector\Rules\LaravelFacadeAssertionsRector;
use Laratesto\Rector\Rules\PhpUnitExceptionExpectationRector;
use Rector\Config\RectorConfig;
use Testo\Bridge\Rector\Set\TestoRectorSetList;
use Testo\Bridge\Rector\PhpunitToTesto\ExpectExceptionToTestoRector;

return static function (RectorConfig $rectorConfig): void {
    // The package root is either the project root (dev) or vendor/ichinya/laratesto
    // (consumer). From packages/rector/config/sets, four ups reach that root.
    $packageRoot = \dirname(__DIR__, 4);

    // Make the PHPUnit compatibility shim and Laravel's test traits discoverable
    // to Rector's source locator even when phpunit/phpunit is not installed.
    $rectorConfig->autoloadPaths(AutoloadPaths::forPackage($packageRoot));

    // Generic PHPUnit -> Testo part (upstream), applied BEFORE Laravel-specific rules.
    $rectorConfig->import(TestoRectorSetList::PHPUNIT_TO_TESTO);
    $rectorConfig->skip([ExpectExceptionToTestoRector::class]);
    $rectorConfig->rule(PhpUnitExceptionExpectationRector::class);
    $rectorConfig->rule(PhpUnitCompatibilityRector::class);
    $rectorConfig->rule(LaravelFacadeAssertionsRector::class);

    // One shared hierarchy recognition state: LaravelBaseClassRector::configure()
    // adopts the configured base classes into it and every Laravel rule reads them
    // from there, so a custom base_classes override governs the whole pipeline.
    $rectorConfig->singleton(ConfiguredHierarchy::class);

    // Laravel-specific rules.
    $rectorConfig->ruleWithConfiguration(LaravelBaseClassRector::class, [
        LaravelBaseClassRector::BASE_CLASSES => LaravelBaseClassRector::DEFAULT_BASE_CLASSES,
        LaravelBaseClassRector::TARGET_MODE => LaravelBaseClassRector::TARGET_MODE_BASE_CLASS,
    ]);
    $rectorConfig->rule(LaravelDatabaseTraitsRector::class);
    $rectorConfig->rule(LaravelSourceCompatibleCallsRector::class);
    $rectorConfig->rule(LaravelResidualDetectionRector::class);
};
