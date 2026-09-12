<?php

declare(strict_types=1);

namespace Laratesto\Rector\Configuration;

/**
 * Resolves the directories that Rector must see when phpunit/phpunit is absent.
 *
 * This is used both by the public Rector set and by the package's own pipeline
 * tests so they do not accidentally overwrite the set's autoload configuration
 * with builder-style calls.
 */
final class AutoloadPaths
{
    /**
     * @param non-empty-string $packageRoot
     * @return list<string>
     */
    public static function forPackage(string $packageRoot): array
    {
        $vendorDir = \is_file($packageRoot . '/vendor/autoload.php')
            ? $packageRoot . '/vendor'
            : \dirname($packageRoot) . '/vendor';

        $paths = [
            $packageRoot . '/src/Shim/PhpUnit',
            $packageRoot . '/src/Testing',
        ];

        if (\is_dir($packageRoot . '/src/Shim/SebastianBergmann')) {
            $paths[] = $packageRoot . '/src/Shim/SebastianBergmann';
        }

        if (\is_dir($vendorDir . '/laravel/framework/src/Illuminate/Foundation/Testing')) {
            $paths[] = $vendorDir . '/laravel/framework/src/Illuminate/Foundation/Testing';
        }

        if (\is_dir($vendorDir . '/laravel/framework/src/Illuminate/Foundation/Testing/Concerns')) {
            $paths[] = $vendorDir . '/laravel/framework/src/Illuminate/Foundation/Testing/Concerns';
        }

        if (\is_dir($vendorDir . '/laravel/framework/src/Illuminate/Testing')) {
            $paths[] = $vendorDir . '/laravel/framework/src/Illuminate/Testing';
        }

        if (\is_dir($vendorDir . '/laravel/framework/src/Illuminate/Http')) {
            $paths[] = $vendorDir . '/laravel/framework/src/Illuminate/Http';
        }

        if (\is_dir($vendorDir . '/laravel/framework/src/Illuminate/Mail')) {
            $paths[] = $vendorDir . '/laravel/framework/src/Illuminate/Mail';
        }

        return \array_filter($paths, 'is_dir');
    }
}
