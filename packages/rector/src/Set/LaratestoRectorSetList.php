<?php

declare(strict_types=1);

namespace Laratesto\Rector\Set;

/**
 * Entry point for users: rector.php in the migrated project imports
 * LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO.
 *
 * Generic PHPUnit-to-Testo conversions come from upstream testo/bridge-rector
 * and are imported internally by the set config, so users connect ONE set.
 */
final class LaratestoRectorSetList
{
    public const LARAVEL_PHPUNIT_TO_LARATESTO =
        __DIR__ . '/../../config/sets/laravel-phpunit-to-laratesto.php';
}
