<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Configuration;

use Composer\InstalledVersions;
use Laratesto\Rector\Configuration\AutoloadPaths;
use Testo\Assert;
use Testo\Test;

final class AutoloadPathsTest
{
    #[Test]
    public function installedPackagePathsDoNotAssumeAVendorLayout(): void
    {
        $base = \sys_get_temp_dir() . '/laratesto-autoload-' . \bin2hex(\random_bytes(8));
        $runtime = $base . '/custom-dependencies/runtime';
        $relativePaths = [
            $runtime . '/src/Shim/PhpUnit',
            $runtime . '/src/Testing',
        ];
        foreach ($relativePaths as $path) {
            \mkdir($path, 0777, true);
        }
        try {
            $paths = AutoloadPaths::forPackage($runtime);
            $canonicalRuntime = \realpath($runtime);
            // Missing optional Sebastian shim must not leave holes in the list.
            Assert::same([$canonicalRuntime . '/src/Shim/PhpUnit', $canonicalRuntime . '/src/Testing'], \array_slice($paths, 0, 2));
            Assert::same(\range(0, \count($paths) - 1), \array_keys($paths));
            $framework = \realpath(InstalledVersions::getInstallPath('laravel/framework'));
            Assert::true(\in_array($framework . '/src/Illuminate/Foundation/Testing', $paths, true));
            Assert::true(\in_array($framework . '/src/Illuminate/Http', $paths, true));
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                \rmdir($entry->getPathname());
            }
            \rmdir($base);
        }
    }

    #[Test]
    public function publicSetAndFixtureRunnerDiscoverTheSameRuntimeSources(): void
    {
        $root = \dirname(__DIR__, 3);
        Assert::same(AutoloadPaths::forPackage($root), AutoloadPaths::forPackage());
        Assert::true(\in_array($root . '/src/Shim/PhpUnit', AutoloadPaths::forPackage(), true));
        $runner = new \ReflectionClass(\Testo\Bridge\Rector\Testing\Internal\RectorRunner::class);
        Assert::same(
            AutoloadPaths::forPackage(\realpath($root)),
            $runner->getMethod('discoverAutoloadPaths')->invoke(null),
        );
    }
}
