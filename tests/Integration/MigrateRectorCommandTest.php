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
 *
 * The fixture corpus lives under the fixture app's ignored storage tree, so it
 * is exactly the unrestorable-by-Git input the #10 guard refuses. The apply
 * journeys here therefore carry the explicit --allow-dirty escape (asserting
 * its warning), while the guard's own accept/reject behavior — including the
 * untracked/ignored refusals and the allow-dirty override over a REAL work
 * tree — is covered by MigrateRectorCommandGitFixtureTest and the canned
 * guard tests, which never touch the shared checkout index.
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

final class ManualSeedTest extends TestCase
{
    public function test_seed(): void
    {
        $this->seed();
    }
}
PHP;

    /**
     * Lifecycle-only corpus: the `--target-mode` propagation under test is the
     * base-class wiring, so the corpus isolates it from the database-trait and
     * HTTP conversion surfaces.
     */
    private const TRAIT_CORPUS = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class TraitModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_trait_mode_works(): void
    {
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

            Assert::same($result->exitCode(), 2, 'Manual residuals (seed) must yield exit 2. Output: ' . $result->output());
            Assert::same($before, \md5_file($file), 'A dry-run must not modify processed sources.');

            $payload = \json_decode((string) \file_get_contents($report), true);

            Assert::true(\is_array($payload));
            Assert::same($payload['schema_version'], 1);
            Assert::same($payload['mode'], 'dry-run');

            $codes = \array_map(static fn(array $r): string => $r['code'], $payload['residuals']);

            Assert::true(\in_array('HTTP_UNSUPPORTED_SIGNATURE', $codes, true), 'seed() must be reported: ' . \json_encode($codes));
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
                '--allow-dirty' => true,
            ]);

            Assert::same($first->exitCode(), 2);
            Assert::true(\str_contains($first->output(), 'allow-dirty'), 'The ignored fixture corpus forces the explicit override.');

            $applied = (string) \file_get_contents($file);

            Assert::true(\str_contains($applied, 'Laratesto\\Testing\\LaravelTestCase'), 'The base class must be rewritten in place.');
            Assert::true(\str_contains($applied, '#[\Laratesto\Attribute\RefreshDatabase]'), 'The trait must become an attribute.');
            Assert::true(\str_contains($applied, '#[\Testo\Test]'), 'Test methods must be discoverable by Testo.');
            Assert::true(\str_contains($applied, 'laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE'), 'The unsupported seed helper must stay visible as a residual marker.');

            $hash = \md5_file($file);

            // Second apply: strictly no further changes (idempotency).
            $second = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--apply' => true,
                '--allow-dirty' => true,
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
                '--allow-dirty' => true,
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
            Assert::same(\count($payload['residuals']), 2);
            foreach ($payload['residuals'] as $residual) {
                Assert::same($residual['code'], 'HTTP_UNSUPPORTED_SIGNATURE');
                Assert::same($residual['line'], $markerLine, 'Both rule contributions must report the on-disk marker line.');
            }
        } finally {
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
                '--allow-dirty' => true,
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

    /**
     * PR #8 review point 9: the documented README spelling with forward slashes
     * must reach the rule as the canonical `Tests\ApiTestCase` and convert the
     * corpus exactly like the backslash form.
     */
    #[Test]
    public function documentedForwardSlashBaseClassIsCanonicalizedAndConverts(): void
    {
        [$root, $dir, $file, $report] = $this->customCorpus();

        try {
            $this->registerProvider();

            $result = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--base-class' => ['Tests/ApiTestCase'],
                '--apply' => true,
                '--allow-dirty' => true,
            ]);

            Assert::same(
                0,
                $result->exitCode(),
                'The documented forward-slash spelling must convert like Tests\ApiTestCase. Output: ' . $result->output(),
            );
            Assert::true(
                \str_contains((string) \file_get_contents($file), 'Laratesto\\Testing\\LaravelTestCase'),
                'The custom base class must be converted through the canonicalized override.',
            );
        } finally {
            @\unlink($file);
            @\rmdir($dir);
            @\unlink($report);
        }
    }

    /**
     * PR #8 review point 9: malformed or empty values are rejected with exit 1
     * before any Git check, Rector run or report write happens.
     */
    #[Test]
    public function malformedAndEmptyBaseClassValuesAreRejectedBeforeAnyRun(): void
    {
        [$root, $dir, $file, $report] = $this->customCorpus();

        try {
            $this->registerProvider();

            $before = \md5_file($file);

            // The double backslash a POSIX shell leaves behind: an empty segment.
            $malformed = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--base-class' => ['Tests\\\\ApiTestCase'],
            ]);

            Assert::same(1, $malformed->exitCode(), 'Malformed input must fail the run. Output: ' . $malformed->output());
            Assert::true(\str_contains($malformed->output(), '--base-class'), 'The failing option must be named.');
            Assert::true(\str_contains($malformed->output(), 'Invalid base class'), 'The offending value must be named.');
            Assert::false(\is_file($report), 'No report may be written for rejected input.');

            $empty = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--base-class' => ['   '],
            ]);

            Assert::same(1, $empty->exitCode(), 'Whitespace-only input must fail the run. Output: ' . $empty->output());
            Assert::false(\is_file($report), 'Still no report for rejected input.');
            Assert::same($before, \md5_file($file), 'Rejected input must leave the sources untouched.');
        } finally {
            @\unlink($file);
            @\rmdir($dir);
            @\unlink($report);
        }
    }

    /**
     * The generated RectorConfig must carry the `--target-mode=trait` override: only
     * then does Rector drop the framework parent and wire `InteractsWithLaravel` in
     * as a trait (the default base_class mode rewrites the parent instead, as the
     * sibling tests prove). Verified end to end over a real Rector run with report
     * and idempotency semantics.
     */
    #[Test]
    public function traitTargetModeReachesRectorThroughTheGeneratedConfig(): void
    {
        [$dir, $file, $report] = $this->traitCorpus();

        $this->registerProvider();

        try {
            $first = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--target-mode' => 'trait',
                '--apply' => true,
                '--allow-dirty' => true,
            ]);

            Assert::same(0, $first->exitCode(), 'A clean corpus must migrate with no residuals. Output: ' . $first->output());

            $applied = (string) \file_get_contents($file);

            // The trait wiring is the observable proof that the generated config
            // (RectorConfigWriter writes LaravelBaseClassRector::TARGET_MODE => 'trait')
            // reached Rector: the default mode produces the base-class shape instead.
            Assert::true(\str_contains($applied, 'use \Laratesto\Testing\InteractsWithLaravel;'), 'The class must use the trait: ' . $applied);
            Assert::same(1, \substr_count($applied, 'InteractsWithLaravel'), 'The trait must be wired exactly once.');
            Assert::true(\str_contains($applied, 'function setUpLaravel(): void'), 'The lifecycle hook must be renamed for the trait.');
            Assert::true(\str_contains($applied, '#[\Testo\Test]'), 'Test methods must be discoverable by Testo.');
            Assert::false(\str_contains($applied, 'Laratesto\Testing\LaravelTestCase'), 'Trait mode must not rewrite the parent to the base class.');
            Assert::false(\str_contains($applied, 'extends \Illuminate\Foundation\Testing\TestCase'), 'The framework parent must be dropped.');
            Assert::false(\str_contains($applied, 'parent::setUp()'), 'The dropped parent takes its call with it.');

            $hash = \md5_file($file);

            $second = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
                '--target-mode' => 'trait',
                '--apply' => true,
                '--allow-dirty' => true,
            ]);

            Assert::same(0, $second->exitCode(), 'A second trait-mode apply is a no-op. Output: ' . $second->output());
            Assert::same($hash, \md5_file($file), 'The second apply must not move a byte.');

            $payload = \json_decode((string) \file_get_contents($report), true);
            Assert::same($payload['mode'], 'apply');
            Assert::same($payload['residuals'], []);
        } finally {
            self::cleanup($dir, $report);
        }
    }

    /**
     * @return array{non-empty-string, non-empty-string, non-empty-string}
     */
    private function traitCorpus(): array
    {
        $root = $this->app()->basePath();
        $dir = $root . '/storage/framework/testing/laratesto-trait-' . \getmypid();

        \mkdir($dir, 0777, true);

        $file = $dir . '/TraitModeTest.php';
        \file_put_contents($file, self::TRAIT_CORPUS);

        return [$dir, $file, $root . '/storage/framework/testing/laratesto-trait-report.json'];
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
