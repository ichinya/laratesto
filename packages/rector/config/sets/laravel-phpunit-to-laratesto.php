<?php

declare(strict_types=1);

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Rules\LaravelDatabaseTraitsRector;
use Laratesto\Rector\Rules\LaravelResidualDetectionRector;
use Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector;
use Rector\Config\RectorConfig;
use Testo\Bridge\Rector\Set\TestoRectorSetList;

return static function (RectorConfig $rectorConfig): void {
    // Generic PHPUnit -> Testo part (upstream), applied BEFORE Laravel-specific rules.
    $rectorConfig->import(TestoRectorSetList::PHPUNIT_TO_TESTO);

    // Laravel-specific rules.
    $rectorConfig->ruleWithConfiguration(LaravelBaseClassRector::class, [
        LaravelBaseClassRector::BASE_CLASSES => LaravelBaseClassRector::DEFAULT_BASE_CLASSES,
        LaravelBaseClassRector::TARGET_MODE => LaravelBaseClassRector::TARGET_MODE_BASE_CLASS,
    ]);
    $rectorConfig->rule(LaravelDatabaseTraitsRector::class);
    $rectorConfig->rule(LaravelSourceCompatibleCallsRector::class);
    $rectorConfig->rule(LaravelResidualDetectionRector::class);
};
