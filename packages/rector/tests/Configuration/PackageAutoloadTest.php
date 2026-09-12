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
    public function phpRequirementsMatchTesto(): void
    {
        $rootDir = \dirname(__DIR__, 4);
        $packageDir = \dirname(__DIR__, 2);

        $rootComposer = \json_decode((string) \file_get_contents($rootDir . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $packageComposer = \json_decode((string) \file_get_contents($packageDir . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $testoComposer = \json_decode((string) \file_get_contents($rootDir . '/vendor/testo/testo/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $testoPhp = $testoComposer['require']['php'] ?? null;

        Assert::same('>=8.2', $testoPhp, 'The installed Testo version must keep the documented PHP minimum.');
        Assert::same('>=8.3', $rootComposer['require']['php'] ?? null, 'Laratesto follows Laravel 13\'s PHP minimum, never below Testo\'s.');
        Assert::same($testoPhp, $packageComposer['require']['php'] ?? null, 'The Rector package must use Testo\'s PHP minimum.');
        Assert::same('8.3.0', $rootComposer['config']['platform']['php'] ?? null, 'The lock file must resolve for the minimum supported PHP.');
        Assert::same('^13.0', $rootComposer['require']['laravel/framework'] ?? null, 'Laravel 12 support was dropped; only the Laravel 13 branch is supported.');
    }

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
            $expected['src/' . $relative] = 'Laratesto\\Rector\\' . \str_replace('/', '\\', \substr($relative, 0, -4));
        }

        Assert::true($expected !== [], 'The package source tree must not be empty.');

        foreach ($expected as $file => $class) {
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

    #[Test]
    public function compatibilityBaselineMatchesComposerJson(): void
    {
        // PR #8 review point 8: the documented compatibility baseline is the
        // composer.json baseline — the package keeps Testo's PHP >=8.2 floor
        // while the migrated-to runtime requires PHP >=8.3 with Laravel ^13.0,
        // the deliberate Rector pin, the supported bridge — and the runtime
        // boundary names dev-main, not 0.6.9.
        $packageDir = \dirname(__DIR__, 2);
        $packageComposer = \json_decode((string) \file_get_contents($packageDir . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $readme = (string) \file_get_contents($packageDir . '/README.md');

        Assert::same('>=8.2', $packageComposer['require']['php'] ?? null, 'The package must keep Testo\'s PHP minimum.');
        Assert::same('2.6.2', $packageComposer['require']['rector/rector'] ?? null, 'The Rector pin is deliberate; bumping it needs the corpus gates.');
        Assert::same('^0.2.4', $packageComposer['require']['testo/bridge-rector'] ?? null, 'The supported bridge version must stay documented.');

        $suggest = $packageComposer['suggest']['ichinya/laratesto'] ?? '';
        Assert::true(\str_contains($suggest, 'matching runtime checkout'), 'The suggest must name the consumer installation path.');
        Assert::true(\str_contains($suggest, 'PhpUnitCompatibility'), 'The suggest must name the generated code runtime dependency.');
        Assert::same('<=0.7.0', $packageComposer['conflict']['ichinya/laratesto'] ?? null);

        foreach (['PHP `^8.3`', '(`^0.6.9`)'] as $falseClaim) {
            Assert::true(!\str_contains($readme, $falseClaim), 'The README must not claim: ' . $falseClaim);
        }

        foreach (['>=8.2', '>=8.3', '^13.0', 'dev-main', '0.6.9'] as $fact) {
            Assert::true(\str_contains($readme, $fact), 'The README compatibility baseline must state: ' . $fact);
        }
    }

    #[Test]
    public function composerLockMetadataMatchesThePathPackage(): void
    {
        // PR #8 review point 8: the path-package lock entry must never drift from
        // the package composer.json — an edit without `composer update` fails here.
        $rootDir = \dirname(__DIR__, 4);
        $packageDir = \dirname(__DIR__, 2);

        $packageComposer = \json_decode((string) \file_get_contents($packageDir . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $lock = \json_decode((string) \file_get_contents($rootDir . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);

        $entry = null;
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $locked) {
                if (($locked['name'] ?? null) === $packageComposer['name']) {
                    $entry = $locked;
                    break 2;
                }
            }
        }

        Assert::true($entry !== null, 'composer.lock must contain the ichinya/laratesto-rector path package.');
        Assert::true(($entry['dist']['type'] ?? null) === 'path', 'The locked rector package must come from the path repository.');
        Assert::same('dev-main', $entry['version'] ?? null, 'The path package must be locked from its dev branch.');

        $expected = [
            'keywords' => $packageComposer['keywords'] ?? [],
            'type' => $packageComposer['type'],
            'description' => $packageComposer['description'],
            // Composer accepts a scalar license in composer.json but stores the
            // normalized list form in the lock.
            'license' => (array) ($packageComposer['license'] ?? []),
            'authors' => $packageComposer['authors'],
            'require' => $packageComposer['require'],
            'conflict' => $packageComposer['conflict'],
            'suggest' => $packageComposer['suggest'] ?? [],
            'extra' => $packageComposer['extra'],
            'autoload' => $packageComposer['autoload'],
        ];
        \sort($expected['keywords']);

        $actual = [
            'keywords' => $entry['keywords'] ?? [],
            'type' => $entry['type'] ?? null,
            'description' => $entry['description'] ?? null,
            'license' => $entry['license'] ?? null,
            'authors' => $entry['authors'] ?? null,
            'require' => $entry['require'] ?? null,
            'conflict' => $entry['conflict'] ?? [],
            'suggest' => $entry['suggest'] ?? [],
            'extra' => $entry['extra'] ?? null,
            'autoload' => $entry['autoload'] ?? null,
        ];
        \sort($actual['keywords']);

        Assert::same($expected, $actual, 'composer.lock path-package metadata must match packages/rector/composer.json.');
    }
}
