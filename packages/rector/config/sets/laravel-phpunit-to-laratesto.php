<?php

declare(strict_types=1);

use Laratesto\Rector\Configuration\ConfiguredHierarchy;
use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Rules\LaravelDatabaseTraitsRector;
use Laratesto\Rector\Rules\LaravelResidualDetectionRector;
use Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector;
use Rector\Config\RectorConfig;
use Testo\Bridge\Rector\Set\TestoRectorSetList;

return static function (RectorConfig $rectorConfig): void {
    // Generic PHPUnit -> Testo part (upstream), applied BEFORE Laravel-specific rules.
    $rectorConfig->import(TestoRectorSetList::PHPUNIT_TO_TESTO);

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
