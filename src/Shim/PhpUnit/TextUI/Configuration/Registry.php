<?php

declare(strict_types=1);

namespace PHPUnit\TextUI\Configuration;

/**
 * Stub for PHPUnit TextUI configuration registry.
 */
final class Registry
{
    private static ?Configuration $configuration = null;

    public static function get(): Configuration
    {
        return self::$configuration ?? throw new \AssertionError('No PHPUnit configuration has been loaded.');
    }

    public static function save(Configuration $configuration): void
    {
        self::$configuration = $configuration;
    }
}
