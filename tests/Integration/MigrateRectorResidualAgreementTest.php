<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Laratesto\Rector\Console\ProcessOutcome;
use Laratesto\Rector\Console\ProcessRunner;
use Laratesto\Rector\LaratestoRectorServiceProvider;
use Laratesto\Testing\InteractsWithLaravel;
use Laratesto\Tests\Support\FakeProcessRunner;
use Testo\Assert;
use Testo\Test;

/**
 * PR #8 review point 6: dry-run and apply residual reporting must agree for
 * already-migrated trees. Verified through an injected ProcessRunner — a no-op
 * dry-run over an on-disk marker exits 2, a machine diff overlays the
 * reconstructed new side without duplicating kept markers or reporting removed
 * ones, and both modes report the same residuals for the same tree.
 */
final class MigrateRectorResidualAgreementTest
{
    use InteractsWithLaravel;

    private const FAKE_MARKER = '/* laratesto-residual(code=LARAVEL_FAKE_UNSUPPORTED, rule=LaravelResidualDetectionRector): Mail::fake() has no automatic Laratesto equivalent */';

    private const OUTSIDE_MARKER = '/* laratesto-residual(code=LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY, rule=LaravelResidualDetectionRector): facade call outside a convertible hierarchy */';

    private const CORPUS = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

final class ResidualProbeTest extends \Illuminate\Foundation\Testing\TestCase
{
    public function test_probe(): void
    {
        __FIRST_MARKER__
        \Illuminate\Support\Facades\Mail::fake();
    }
}
PHP;

    #[Test]
    public function aNoOpDryRunOverAnExistingResidualExitsTwo(): void
    {
        [$dir, $report] = $this->probe();

        $this->bindRunner(new ProcessOutcome(0, \json_encode(['totals' => ['errors' => 0]]), ''));

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(2, $result->exitCode(), 'A no-op dry-run over an on-disk residual must exit 2 like apply. Output: ' . $result->output());

        $payload = $this->payload($report);

        Assert::same($payload['mode'], 'dry-run');
        Assert::same(\count($payload['residuals']), 1);
        Assert::same($payload['residuals'][0]['code'], 'LARAVEL_FAKE_UNSUPPORTED');
        Assert::same($payload['residuals'][0]['line'], $this->markerLine(self::FAKE_MARKER), 'The on-disk marker line must be reported.');
        Assert::string($payload['residuals'][0]['file'])->contains('ResidualProbeTest.php');
    }

    #[Test]
    public function aDryRunOverlaysTheReconstructedNewSideWithoutDuplicates(): void
    {
        [$dir, $report, $file] = $this->probe();

        $this->bindRunner(new ProcessOutcome(0, \json_encode([
            'totals' => ['errors' => 0],
            'file_diffs' => [
                ['file' => $this->relativeFile($dir), 'diff' => $this->overlayDiff()],
            ],
        ]), ''));

        $before = \md5_file($file);

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(2, $result->exitCode(), 'Output: ' . $result->output());
        Assert::same($before, \md5_file($file), 'A dry-run must not modify processed sources.');

        $residuals = $this->payload($report)['residuals'];

        Assert::same(\count($residuals), 2, 'The kept marker must appear exactly once — no disk-plus-virtual duplicate.');
        Assert::same($residuals[0]['code'], 'LARAVEL_FAKE_UNSUPPORTED');
        Assert::same($residuals[0]['line'], $this->markerLine(self::FAKE_MARKER) + 1, 'The kept marker must carry its NEW-SIDE line number.');
        Assert::same($residuals[1]['code'], 'LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY');
        Assert::same($residuals[1]['line'], $this->markerLine(self::FAKE_MARKER) + 3, 'A freshly added marker must be reported at its new-side line.');
    }

    #[Test]
    public function aDryRunDoesNotReportAMarkerTheChangeRemoves(): void
    {
        [$dir, $report] = $this->probe();

        $this->bindRunner(new ProcessOutcome(0, \json_encode([
            'totals' => ['errors' => 0],
            'file_diffs' => [
                ['file' => $this->relativeFile($dir), 'diff' => $this->removalDiff()],
            ],
        ]), ''));

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(0, $result->exitCode(), 'A change that removes the last residual is clean. Output: ' . $result->output());
        Assert::same($this->payload($report)['residuals'], [], 'The reconstructed new side wins over the marker still on disk.');
    }

    #[Test]
    public function applyAndDryRunReportTheSameResidualsForTheSameTree(): void
    {
        [$dir, $report] = $this->probe();

        $this->bindRunner(new ProcessOutcome(0, \json_encode(['totals' => ['errors' => 0]]), ''));

        $apply = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
            '--apply' => true,
        ]);

        Assert::same(2, $apply->exitCode());
        $applyResiduals = $this->payload($report)['residuals'];

        $dryRun = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(2, $dryRun->exitCode());
        Assert::same($this->payload($report)['residuals'], $applyResiduals, 'Dry-run and apply must report identical residuals for an already-migrated tree.');
    }

    /**
     * A throwaway processed directory holding the probe corpus with one on-disk
     * residual marker; cleaned up when the process ends.
     *
     * @return array{non-empty-string, non-empty-string, non-empty-string}
     */
    private function probe(): array
    {
        $root = $this->app()->basePath();
        $dir = $root . '/storage/framework/testing/laratesto-agreement-' . \uniqid();

        \mkdir($dir, 0777, true);

        $file = $dir . '/ResidualProbeTest.php';
        \file_put_contents($file, $this->corpus());

        $report = $root . '/storage/framework/testing/laratesto-agreement-report-' . \uniqid() . '.json';

        \register_shutdown_function(static function () use ($dir, $report): void {
            foreach (\glob($dir . '/*.php') ?: [] as $probeFile) {
                @\unlink($probeFile);
            }

            @\rmdir($dir);
            @\unlink($report);
        });

        return [$dir, $report, $file];
    }

    private function bindRunner(ProcessOutcome $rector): void
    {
        $runner = new FakeProcessRunner(
            $rector,
            new ProcessOutcome(0, "true\n", ''),
            new ProcessOutcome(0, '', ''),
        );

        $this->app()->bind(ProcessRunner::class, static fn(): ProcessRunner => $runner);
        $this->app()->register(LaratestoRectorServiceProvider::class);
    }

    private function corpus(): string
    {
        return \str_replace('__FIRST_MARKER__', self::FAKE_MARKER, self::CORPUS);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $report): array
    {
        $payload = \json_decode((string) \file_get_contents($report), true);

        Assert::true(\is_array($payload), 'The report must exist and be valid JSON.');

        return $payload;
    }

    private function relativeFile(string $dir): string
    {
        $root = $this->app()->basePath();

        return \str_replace('\\', '/', \substr($dir, \strlen($root) + 1)) . '/ResidualProbeTest.php';
    }

    private function markerLine(string $marker): int
    {
        return \substr_count($this->corpus(), "\n", 0, (int) \strpos($this->corpus(), $marker)) + 1;
    }

    /**
     * Keeps the on-disk marker (shifted by one inserted line) and adds a second
     * marker — the new side contains both, the disk only the first.
     */
    private function overlayDiff(): string
    {
        return \str_replace(
            ['__FIRST_MARKER__', '__SECOND_MARKER__'],
            [self::FAKE_MARKER, self::OUTSIDE_MARKER],
            <<<'DIFF'
@@ -9,5 +9,7 @@
     public function test_probe(): void
     {
+        $probe = true;
         __FIRST_MARKER__
         \Illuminate\Support\Facades\Mail::fake();
+        __SECOND_MARKER__
     }
DIFF,
        );
    }

    /**
     * Removes the on-disk marker — the new side is clean while the disk is not.
     */
    private function removalDiff(): string
    {
        return \str_replace(
            '__FIRST_MARKER__',
            self::FAKE_MARKER,
            <<<'DIFF'
@@ -10,4 +10,3 @@
     {
-        __FIRST_MARKER__
         \Illuminate\Support\Facades\Mail::fake();
     }
DIFF,
        );
    }
}
