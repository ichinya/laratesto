<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Configuration;

use Testo\Assert;
use Testo\Test;

/**
 * Packaging contract (PR #8 fix plan, stage 9): the standalone rector package must
 * be autoloadable through its own composer.json — the consuming project must not
 * need a duplicate PSR-4 mapping for `Laratesto\Rector\`.
 */
final class PackageAutoloadTest
{
    #[Test]
    public function thePackageComposerJsonMapsTheWholeSourceTree(): void
    {
        $packageDir = \dirname(__DIR__, 2);

        $composer = \json_decode((string) \file_get_contents($packageDir . '/composer.json'), true);

        $prefixes = $composer['autoload']['psr-4'] ?? [];

        Assert::true(
            isset($prefixes['Laratesto\\Rector\\']) && $prefixes['Laratesto\\Rector\\'] === 'src/',
            'The package must map Laratesto\Rector\ to its src/ directory.',
        );

        $expected = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packageDir . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = \str_replace('\\', '/', \substr((string) $file->getPathname(), \strlen($packageDir . '/src/')));
            $expected['src/' . $relative] = 'Laratesto\Rector\' . \str_replace('/', '\', \substr($relative, 0, -4));
        }

        Assert::true($expected !== [], 'The package source tree must not be empty.');

        foreach (\array_keys($expected) as $file => $class) {
            Assert::true(\is_file($packageDir . '/' . $file), 'Missing package source file: ' . $file);
            Assert::true(\class_exists($class) || \interface_exists($class) || \trait_exists($class) || \enum_exists($class), 'The class must load through the package autoload: ' . $class);
        }
    }

    #[Test]
    public function theInstalledPackageIsTheRepoSource(): void
    {
        // Dev mode: the path repository is junctioned/symlinked into vendor, so the
        // suite always exercises the live repo source, never a stale copy.
        $installed = \realpath(\dirname(__DIR__, 4) . '/vendor/ichinya/laratesto-rector/src');
        $source = \realpath(\dirname(__DIR__, 2) . '/src');

        Assert::same($source, $installed, 'vendor/ichinya/laratesto-rector must be the repo source (path repository in symlink mode).');
    }
}
