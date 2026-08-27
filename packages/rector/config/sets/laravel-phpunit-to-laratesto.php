<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Testo\Bridge\Rector\Set\TestoRectorSetList;

return static function (RectorConfig $rectorConfig): void {
    // Generic PHPUnit -> Testo part (upstream), applied BEFORE Laravel-specific rules.
    $rectorConfig->import(TestoRectorSetList::PHPUNIT_TO_TESTO);

    // Laravel-specific rules are added by tickets 02-04.
    // $rectorConfig->rule(...);
};
