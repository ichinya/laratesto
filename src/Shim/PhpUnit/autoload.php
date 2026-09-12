<?php

declare(strict_types=1);

/**
 * Conditional autoloader for the PHPUnit compatibility shim.
 *
 * The shim is only used when phpunit/phpunit is not installed. It is appended
 * to the autoloader stack so the real PHPUnit package, if present, takes
 * precedence.
 */

if (\function_exists('opcache_invalidate')) {
    $invalidateDir = static function (string $dir): void {
        if (!\is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
        );

        foreach ($iterator as $file) {
            if (\str_ends_with((string) $file, '.php')) {
                \opcache_invalidate((string) $file, true);
            }
        }
    };

    $invalidateDir(__DIR__);
    $invalidateDir(__DIR__ . '/../SebastianBergmann');
}

spl_autoload_register(static function (string $class): void {
    if (\str_starts_with($class, 'PHPUnit\\')) {
        $relative = \substr($class, 7);
        $base = __DIR__;
    } elseif (\str_starts_with($class, 'SebastianBergmann\\')) {
        $relative = \substr($class, 19);
        $base = __DIR__ . '/../SebastianBergmann';
    } else {
        return;
    }

    $path = $base . '/' . \str_replace('\\', '/', $relative) . '.php';

    if (!\is_file($path)) {
        return;
    }

    if (\function_exists('opcache_invalidate')) {
        \opcache_invalidate($path, true);
    }

    require $path;
}, true, false);

// Eagerly declare the two classes that Laravel's test support files extend,
// so PHPStan/Rector can include Illuminate\Testing\Assert and
// Illuminate\Foundation\Testing\TestCase even when phpunit/phpunit is absent.
// Composer's loader is first in the stack, so if a real PHPUnit package is
// installed it will be used instead of the shim.
foreach (['PHPUnit\\Framework\\Assert', 'PHPUnit\\Framework\\TestCase'] as $shimClass) {
    if (! \class_exists($shimClass, false)) {
        \class_exists($shimClass, true);
    }
}
