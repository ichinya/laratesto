<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Laratesto\Rector\LaratestoRectorServiceProvider;
use Laratesto\Testing\InteractsWithLaravel;
use Testo\Assert;
use Testo\Test;

/**
 * Ticket 06 acceptance: the Artisan journey over a throwaway corpus inside the
 * fixture application — dry-run leaves sources untouched but atomically replaces
 * the JSON report; apply rewrites in place; manual residuals yield exit 2.
 */
final class MigrateRectorCommandTest
{
    use InteractsWithLaravel;

    private const CORPUS = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Mail;

final class SignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_signup_sends_mail(): void
    {
        Mail::fake();

        $this->postJson('/signup', ['email' => 'a@b.c'])->assertStatus(201);
    }
}
PHP;

    #[Test]
    public function dryRunLeavesSourcesUntouchedAndWritesTheReport(): void
    {
        [$dir, $file, $report] = $this->corpus('dry-run');

        $this->registerProvider();

        try {
            $before = \md5_file($file);

            $result = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
            ]);

            Assert::same($result->exitCode(), 2, 'Manual residuals (Mail::fake) must yield exit 2. Output: ' . $result->output());
            Assert::same($before, \md5_file($file), 'A dry-run must not modify processed sources.');

            $payload = \json_decode((string) \file_get_contents($report), true);

            Assert::true(\is_array($payload));
            Assert::same($payload['schema_version'], 1);
            Assert::same($payload['mode'], 'dry-run');

            $codes = \array_map(static fn(array $r): string => $r['code'], $payload['residuals']);

            Assert::true(\in_array('LARAVEL_FAKE_UNSUPPORTED', $codes, true), 'Mail::fake() must be reported: ' . \json_encode($codes));
        } finally {
            self::cleanup($dir, $report);
        }
    }

    #[Test]
    public function applyRewritesInPlaceAndStaysIdempotent(): void
    {
        [$dir, $file, $report] = $this->corpus('apply');

        $this->registerProvider();

        try {
            $first = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--apply' => true,
            ]);

            Assert::same($first->exitCode(), 2);

            $applied = (string) \file_get_contents($file);

            Assert::true(\str_contains($applied, 'Laratesto\\Testing\\LaravelTestCase'), 'The base class must be rewritten in place.');
            Assert::true(\str_contains($applied, '#[\Laratesto\Attribute\RefreshDatabase]'), 'The trait must become an attribute.');
            Assert::true(\str_contains($applied, '#[\Testo\Test]'), 'Test methods must be discoverable by Testo.');
            Assert::true(\str_contains($applied, 'laratesto-residual(code=LARAVEL_FAKE_UNSUPPORTED'), 'The fake must stay visible as a residual marker.');

            $hash = \md5_file($file);

            // Second apply: strictly no further changes (idempotency).
            $second = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--apply' => true,
            ]);

            Assert::same($second->exitCode(), 2);
            Assert::same($hash, \md5_file($file), 'A second run must not change already-migrated sources.');

            $payload = \json_decode((string) \file_get_contents($report), true);
            Assert::same($payload['mode'], 'apply');
        } finally {
            self::cleanup($dir, $report);
        }
    }

    /**
     * PR #8 review point 6: over an already-migrated tree the second dry-run is a
     * real Rector no-op (no file diffs at all) — the residual marker lives only on
     * disk, and the dry-run must still exit 2 and report it, exactly like apply.
     */
    #[Test]
    public function dryRunOverAnAlreadyMigratedTreeStillReportsTheResiduals(): void
    {
        [$dir, $file, $report] = $this->corpus('dry-after-apply');

        $this->registerProvider();

        try {
            $apply = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--apply' => true,
            ]);

            Assert::same($apply->exitCode(), 2, 'The first apply must leave a manual residual behind. Output: ' . $apply->output());

            $applied = (string) \file_get_contents($file);
            $markerLine = \substr_count($applied, "\n", 0, (int) \strpos($applied, 'laratesto-residual(code=')) + 1;
            $hash = \md5_file($file);

            $dry = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
            ]);

            Assert::same($dry->exitCode(), 2, 'A no-op dry-run over an already-migrated tree must exit 2 like apply. Output: ' . $dry->output());
            Assert::same($hash, \md5_file($file), 'The dry-run must not modify the already-migrated source.');

            $payload = \json_decode((string) \file_get_contents($report), true);

            Assert::same($payload['mode'], 'dry-run');
            Assert::same(\count($payload['residuals']), 1);
            Assert::same($payload['residuals'][0]['code'], 'LARAVEL_FAKE_UNSUPPORTED');
            Assert::same($payload['residuals'][0]['line'], $markerLine, 'The on-disk marker line must be reported.');
        } finally {
            self::cleanup($dir, $report);
        }
    }

    #[Test]
    public function applyRefusesDirtyProcessedPaths(): void
    {
        [$dir, $file, $report] = $this->corpus('dirty');

        $this->registerProvider();

        // Staging the corpus makes it non-untracked — the guard must refuse.
        // The index entry is always dropped again in the finally block.
        \exec(\sprintf('git add -f -- %s 2>&1', \escapeshellarg($file)), $out, $added);

        try {
            if ($added !== 0) {
                // Not inside a usable Git work tree (e.g. an export) — the guard's
                // other branch covers that; nothing more to assert here.
                return;
            }

            $result = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--apply' => true,
            ]);

            Assert::same(
                $result->exitCode(),
                1,
                'A staged (non-untracked) processed path must block --apply. Output: ' . $result->output(),
            );
            Assert::true(\str_contains($result->output(), 'Refusing --apply'));
        } finally {
            \exec(\sprintf('git reset -q HEAD -- %s 2>&1', \escapeshellarg($file)));

            self::cleanup($dir, $report);
        }
    }

    private function registerProvider(): void
    {
        $this->app()->register(LaratestoRectorServiceProvider::class);
    }

    #[Test]
    public function customBaseClassReachesTheRuleConfiguration(): void
    {
        [$root, $dir, $file, $report] = $this->customCorpus();

        try {
            $this->registerProvider();

            $result = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--base-class' => ['Tests\ApiTestCase'],
                '--apply' => true,
            ]);

            Assert::same(0, $result->exitCode(), 'No manual residuals expected here: ' . $result->output());

            $applied = (string) \file_get_contents($file);

            Assert::true(
                \str_contains($applied, 'Laratesto\\Testing\\LaravelTestCase'),
                'The custom base class must be converted too.',
            );
        } finally {
            @\unlink($file);
            @\rmdir($dir);
            @\unlink($report);
        }
    }

    #[Test]
    public function allowDirtyOverridesTheGuardWithAWarning(): void
    {
        [$dir, $file, $report] = $this->corpus('allow-dirty');

        $this->registerProvider();

        \exec(\sprintf('git add -f -- %s 2>&1', \escapeshellarg($file)), $out, $added);

        try {
            if ($added !== 0) {
                return;
            }

            $result = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--apply' => true,
                '--allow-dirty' => true,
            ]);

            Assert::same(2, $result->exitCode());
            Assert::true(
                \str_contains($result->output(), 'allow-dirty'),
                'The override must print an explicit warning about the lost safe rollback.',
            );
            Assert::true(\str_contains((string) \file_get_contents($file), 'Laratesto\\Testing\\LaravelTestCase'));
        } finally {
            \exec(\sprintf('git reset -q HEAD -- %s 2>&1', \escapeshellarg($file)));

            self::cleanup($dir, $report);
        }
    }

    /**
     * A corpus whose base class is a project-specific `Tests\ApiTestCase` — reachable
     * only through the `--base-class` override.
     *
     * @return array{non-empty-string, non-empty-string, non-empty-string, non-empty-string}
     */
    private function customCorpus(): array
    {
        $root = $this->app()->basePath();
        $dir = $root . '/storage/framework/testing/laratesto-custom-' . \getmypid();

        \mkdir($dir, 0777, true);

        $file = $dir . '/ApiTest.php';

        \file_put_contents($file, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Tests;

            abstract class ApiTestCase extends \Illuminate\Foundation\Testing\TestCase {}

            namespace Tests\Feature;

            use Tests\ApiTestCase;

            final class ApiTest extends ApiTestCase
            {
                public function test_index(): void
                {
                    $this->get('/api')->assertStatus(200);
                }
            }
            PHP);

        return [$root, $dir, $file, $root . '/storage/framework/testing/laratesto-custom-report.json'];
    }

    /**
     * @return array{non-empty-string, non-empty-string, non-empty-string}
     */
    private function corpus(string $name): array
    {
        $root = $this->app()->basePath();
        $dir = $root . '/storage/framework/testing/laratesto-' . $name . '-' . \getmypid();

        \mkdir($dir, 0777, true);

        $file = $dir . '/SignupTest.php';
        \file_put_contents($file, self::CORPUS);

        return [$dir, $file, $root . '/storage/framework/testing/laratesto-' . $name . '-report.json'];
    }

    private static function cleanup(string $dir, string $report): void
    {
        foreach (\glob($dir . '/*.php') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($dir);
        @\unlink($report);
    }
}
