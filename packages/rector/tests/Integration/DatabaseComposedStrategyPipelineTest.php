<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * The confirmed composed-project-trait gap (PR8 review #4), end to end over the
 * real public set: a framework database strategy that reaches the class only
 * through a project trait's own `use RefreshDatabase` never converts (project
 * traits are not converted), so the migration must keep the strategy visible
 * through the canonical residual marker — same file, cross file, and through a
 * resolvable ancestor's composition — while a class whose composed traits carry
 * no framework strategy still migrates clean without any residual. The
 * idempotency proof compares the second run raw byte-for-byte.
 */
final class DatabaseComposedStrategyPipelineTest
{
    private const TRAIT_FILE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Composed;

        use Illuminate\Foundation\Testing\RefreshDatabase;

        trait RunsMigrations
        {
            use RefreshDatabase;
        }
        PHP;

    private const CROSS_FILE_CONSUMER = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Composed;

        use Illuminate\Foundation\Testing\TestCase;

        final class ComposedCrossFileTest extends TestCase
        {
            use RunsMigrations;

            public function testDatabase(): void
            {
                $this->assertDatabaseHas('things', ['name' => 'seeded']);
            }
        }
        PHP;

    private const CLEAN_TRAIT_FILE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Composed;

        trait PlainHelpers
        {
            public function helpersWork(): bool
            {
                return true;
            }
        }
        PHP;

    private const CLEAN_CONTROL = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Composed;

        use Illuminate\Foundation\Testing\RefreshDatabase;
        use Illuminate\Foundation\Testing\TestCase;

        final class CleanControlTest extends TestCase
        {
            use RefreshDatabase;
            use PlainHelpers;

            public function testDatabase(): void
            {
                $this->assertDatabaseHas('things', ['name' => 'seeded']);
            }
        }
        PHP;

    private const ANCESTOR_BASE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Composed;

        use Illuminate\Foundation\Testing\RefreshDatabase;
        use Illuminate\Foundation\Testing\TestCase;

        trait InheritedMigrations
        {
            use RefreshDatabase;
        }

        abstract class ComposedBase extends TestCase
        {
            use InheritedMigrations;
        }
        PHP;

    private const ANCESTOR_CHILD = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Composed;

        final class ComposedChildTest extends ComposedBase
        {
            public function testDatabase(): void
            {
                $this->assertDatabaseHas('things', ['name' => 'seeded']);
            }
        }
        PHP;

    private const MIGRATED_CLEAN_BASE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Composed;

        use Illuminate\Foundation\Testing\TestCase;

        trait PlainHelpers
        {
            public function helpersWork(): bool
            {
                return true;
            }
        }

        abstract class MigratedBase extends TestCase
        {
            use PlainHelpers;
        }
        PHP;

    private const MIGRATED_CHILD = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Composed;

        final class MigratedChildTest extends MigratedBase
        {
            public function testDatabase(): void
            {
                $this->assertDatabaseHas('things', ['name' => 'seeded']);
            }
        }
        PHP;

    private const MIGRATED_WITH_LIVING_TRAIT_BASE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Composed;

        use Illuminate\Foundation\Testing\RefreshDatabase;
        use Illuminate\Foundation\Testing\TestCase;

        trait InheritedMigrations
        {
            use RefreshDatabase;
        }

        #[\Laratesto\Attribute\RefreshDatabase]
        abstract class StillComposedBase extends TestCase
        {
            use InheritedMigrations;
        }
        PHP;

    private const STILL_COMPOSED_CHILD = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Composed;

        final class StillComposedChildTest extends StillComposedBase
        {
            public function testDatabase(): void
            {
                $this->assertDatabaseHas('things', ['name' => 'seeded']);
            }
        }
        PHP;

    #[Test]
    public function hiddenStrategiesStayVisibleAcrossFileBoundaries(): void
    {
        $snapshot = self::runFullSetTwice([
            'RunsMigrations.php' => self::TRAIT_FILE,
            'ComposedCrossFileTest.php' => self::CROSS_FILE_CONSUMER,
            'CleanHelpers.php' => self::CLEAN_TRAIT_FILE,
            'CleanControlTest.php' => self::CLEAN_CONTROL,
        ]);

        $consumer = Assert::string(self::toLf($snapshot['ComposedCrossFileTest.php']));
        // The cross-file composed strategy is visible through the canonical
        // residual marker naming the strategy and the project trait.
        $consumer->contains('laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION');
        $consumer->contains('framework database strategy Illuminate\Foundation\Testing\RefreshDatabase');
        $consumer->contains('arrives through project trait Tests\Composed\RunsMigrations');

        // The trait itself is untouched: it keeps the strategy for the manual
        // migration.
        $traitFile = Assert::string(self::toLf($snapshot['RunsMigrations.php']));
        $traitFile->contains('use RefreshDatabase;');
        $traitFile->notContains('laratesto-residual');

        // The composed traits without a framework strategy are no obstacle: the
        // control converts clean without any residual.
        $control = Assert::string(self::toLf($snapshot['CleanControlTest.php']));
        $control->contains('#[\Laratesto\Attribute\RefreshDatabase]');
        $control->contains('use PlainHelpers;');
        $control->notContains('laratesto-residual');
    }

    #[Test]
    public function ancestorComposedStrategiesStayVisibleOnTheChild(): void
    {
        $snapshot = self::runFullSetTwice([
            'ComposedBase.php' => self::ANCESTOR_BASE,
            'ComposedChildTest.php' => self::ANCESTOR_CHILD,
        ], baseClasses: ['Illuminate\Foundation\Testing\TestCase', 'Tests\Composed\ComposedBase']);

        $child = Assert::string(self::toLf($snapshot['ComposedChildTest.php']));

        // The strategy reaches the child through the ancestor's composed trait
        // only: the residual names the strategy and that trait, on the child
        // that silently loses it when the hierarchy migrates.
        $child->contains('laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION');
        $child->contains('framework database strategy Illuminate\Foundation\Testing\RefreshDatabase');
        $child->contains('arrives through project trait Tests\Composed\InheritedMigrations on ancestor Tests\Composed\ComposedBase');
    }

    #[Test]
    public function attributeCarryingAncestorsAreJudgedByTheirLivingComposition(): void
    {
        $snapshot = self::runFullSetTwice([
            'MigratedBase.php' => self::MIGRATED_CLEAN_BASE,
            'MigratedChildTest.php' => self::MIGRATED_CHILD,
            'StillComposedBase.php' => self::MIGRATED_WITH_LIVING_TRAIT_BASE,
            'StillComposedChildTest.php' => self::STILL_COMPOSED_CHILD,
        ], baseClasses: [
            'Illuminate\Foundation\Testing\TestCase',
            'Tests\Composed\MigratedBase',
            'Tests\Composed\StillComposedBase',
        ]);
        // Positive control: the ancestor carries only the migrated attribute and
        // composes no framework strategy, so the child migrates clean — no
        // residual, nothing to lose.
        $cleanChild = Assert::string(self::toLf($snapshot['MigratedChildTest.php']));
        $cleanChild->notContains('laratesto-residual');

        // Negative control: the ancestor carries the attribute, but its composed
        // project trait STILL uses RefreshDatabase. Attribute presence never
        // proves the trait was rewritten (no rule rewrites traits), so the
        // strategy still flattens into the hierarchy and must stay flagged.
        $flaggedChild = Assert::string(self::toLf($snapshot['StillComposedChildTest.php']));
        $flaggedChild->contains('laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION');
        $flaggedChild->contains('arrives through project trait Tests\Composed\InheritedMigrations on ancestor Tests\Composed\StillComposedBase');
    }

    private static function toLf(string $contents): string
    {
        return str_replace("\r\n", "\n", $contents);
    }

    /**
     * Runs the real `rector` binary with the full public set over a throwaway corpus
     * copy, twice, and returns the corpus after the first run — asserting on the way
     * that the first run changed something and the second left every file raw
     * byte-identical. argv-array invocation bypasses the shell: no escaping needed on
     * any platform.
     *
     * @param array<string, string> $files Corpus file name => contents
     * @param list<non-empty-string> $baseClasses Additional configured base classes
     *
     * @return array<string, string> Corpus file name => raw contents after run 1
     */
    private static function runFullSetTwice(array $files, array $baseClasses = []): array
    {
        $rootDir = \dirname(__DIR__, 4);
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';

        $tmpDir = \sys_get_temp_dir() . '/pr8-composed-db-fix-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        self::recursiveRemove($tmpDir);

        $corpusDir = $tmpDir . '/corpus';
        Assert::true(\mkdir($corpusDir, 0777, true) || \is_dir($corpusDir));

        try {
            foreach ($files as $name => $contents) {
                \file_put_contents($corpusDir . '/' . $name, $contents);
            }

            $sets = \var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            $cacheDirectory = \var_export($tmpDir . '/rector-cache', true);
            $withPaths = \var_export($corpusDir, true);
            $withBases = '';

            if ($baseClasses !== []) {
                $withBases = \sprintf(
                    "    ->withConfiguredRule(\\%s::class, [\n        \\%s::BASE_CLASSES => %s,\n    ])\n",
                    LaravelBaseClassRector::class,
                    LaravelBaseClassRector::class,
                    \var_export($baseClasses, true),
                );
            }

            $config = <<<PHP
                <?php

                declare(strict_types=1);

                use Rector\Config\RectorConfig;

                return RectorConfig::configure()
                    ->withPaths([{$withPaths}])
                    ->withSets([{$sets}])
                    ->withCache(cacheDirectory: {$cacheDirectory})
                {$withBases};
                PHP;

            \file_put_contents($tmpDir . '/rector.php', $config);

            $runOnce = static function () use ($rectorBin, $tmpDir, $rootDir): void {
                $process = new Process(
                    [PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'],
                    $rootDir,
                    timeout: 300.0,
                );
                $process->run();

                Assert::same(0, $process->getExitCode(), \sprintf(
                    "rector failed (exit %d).\nSTDOUT:\n%s\nSTDERR:\n%s",
                    (int) $process->getExitCode(),
                    $process->getOutput(),
                    $process->getErrorOutput(),
                ));
            };

            // Run 1: allowed to modify — and must actually change at least one input
            // file, or the corpus is stale and the idempotency proof is vacuous.
            // Run 2: strictly forbidden to touch a single raw byte.
            $runOnce();

            $snapshot = self::corpus($corpusDir);
            Assert::true($snapshot !== [], 'The corpus disappeared after the first run.');
            Assert::true($snapshot != $files, 'The first Rector run must change at least one input file.');

            // Every produced file must stay syntactically valid PHP: a residual
            // marker is a comment, never a broken file.
            foreach (\array_keys($snapshot) as $name) {
                $lint = new Process([PHP_BINARY, '-l', $corpusDir . '/' . $name]);
                $lint->run();

                Assert::same(0, $lint->getExitCode(), \sprintf(
                    "php -l failed for %s:\n%s\n%s",
                    $name,
                    $lint->getOutput(),
                    $lint->getErrorOutput(),
                ));
            }

            $runOnce();

            Assert::same(self::corpus($corpusDir), $snapshot, 'Second run modified an already-migrated file.');

            return $snapshot;
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function corpus(string $dir): array
    {
        $snapshot = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $fileInfo) {
            if ($fileInfo->isFile()) {
                $snapshot[(string) $fileInfo->getBasename()] = (string) \file_get_contents((string) $fileInfo->getPathname());
            }
        }

        return $snapshot;
    }

    private static function recursiveRemove(string $dir): void
    {
        if (! \is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $fileInfo) {
            $fileInfo->isDir() ? \rmdir((string) $fileInfo->getPathname()) : \unlink((string) $fileInfo->getPathname());
        }

        \rmdir($dir);
    }
}
