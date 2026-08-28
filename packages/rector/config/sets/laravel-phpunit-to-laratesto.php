<?php

declare(strict_types=1);

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Rules\LaravelDatabaseTraitsRector;
use Rector\Config\RectorConfig;
use Testo\Bridge\Rector\Set\TestoRectorSetList;

return static function (RectorConfig $rectorConfig): void {
    // Generic PHPUnit -> Testo part (upstream), applied BEFORE Laravel-specific rules.
    $rectorConfig->import(TestoRectorSetList::PHPUNIT_TO_TESTO);

    // Laravel-specific rules.
    $rectorConfig->rule(LaravelBaseClassRector::class);
    $rectorConfig->rule(LaravelDatabaseTraitsRector::class);
};
