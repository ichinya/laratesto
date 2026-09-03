<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Laratesto\LaratestoServiceProvider;
use Laratesto\Testing\InteractsWithLaravel;
use Testo\Assert;
use Testo\Test;

/**
 * The deprecated string-based migrator prints exactly one deprecation warning per
 * run, keeps doing its former job and points only at stable references: the
 * replacement command, its --help and the published package name — never a
 * repository-only path an installed package cannot resolve.
 */
final class MigratePhpUnitCommandDeprecationTest
{
    use InteractsWithLaravel;

    #[Test]
    public function warnsOnceAndStillMigrates(): void
    {
        $this->app()->register(LaratestoServiceProvider::class);

        $root = $this->app()->basePath();
        $dir = $root . '/storage/framework/testing/deprecated-migrator-' . \getmypid();

        \mkdir($dir, 0777, true);

        $source = $dir . '/LegacyTest.php';

        \file_put_contents($source, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Tests\Unit;

            use PHPUnit\Framework\TestCase;

            final class LegacyTest extends TestCase
            {
                public function test_value(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);

        try {
            $result = $this->artisan('laratesto:migrate-phpunit', [
                'source' => $dir,
                '--target' => $dir . '/out',
                '--dry-run' => true,
            ]);

            $output = $result->output();

            // Exactly one warning per run — not per file.
            Assert::same(
                \substr_count($output, 'is deprecated'),
                1,
                'Expected exactly one deprecation warning. Output: ' . $output,
            );
            Assert::true(
                \str_contains($output, 'laratesto:migrate-rector'),
                'The warning must point at the Rector-based replacement.',
            );
            Assert::false(
                \str_contains($output, 'packages/rector/README.md'),
                'The warning must not reference the repository-only packages/rector/README.md. Output: ' . $output,
            );
            Assert::true(
                \str_contains($output, 'ichinya/laratesto-rector'),
                'The warning must name the package that ships the replacement. Output: ' . $output,
            );
            Assert::true(
                \str_contains($output, '--help'),
                'The warning must point at the replacement command help. Output: ' . $output,
            );

            // The former job still runs: a dry-run analysis happened, not a crash.
            Assert::notSame($result->exitCode(), 1, 'Command should not fail: ' . $output);
        } finally {
            @\unlink($source);
            @\rmdir($dir);
        }
    }
}
