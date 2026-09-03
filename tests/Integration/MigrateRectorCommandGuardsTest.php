<?php

declare(strict_types=1);

namespace Laratesto\Tests\Integration;

use Laratesto\Rector\Console\ProcessOutcome;
use Laratesto\Rector\LaratestoRectorServiceProvider;
use Laratesto\Rector\Console\ProcessRunner;
use Laratesto\Testing\InteractsWithLaravel;
use Laratesto\Tests\Support\FakeProcessRunner;
use Testo\Assert;
use Testo\Test;

/**
 * PR #8 fix plan, stage 7: the migrate-rector guards verified through an injected
 * ProcessRunner — Git failures, dirty paths, the Rector exit matrix and the machine
 * JSON schema all fail closed without ever starting a real Rector process.
 */
final class MigrateRectorCommandGuardsTest
{
    use InteractsWithLaravel;

    private const CORPUS = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Feature;

        final class GuardProbeTest extends \Illuminate\Foundation\Testing\TestCase
        {
            public function test_probe(): void {}
        }
        PHP;

    #[Test]
    public function anInvalidTargetModeIsRejectedBeforeAnyProcessRuns(): void
    {
        [$runner, $dir, $report] = $this->probe();

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
            '--target-mode' => 'trait_class',
        ]);

        Assert::same(1, $result->exitCode());
        Assert::string($result->output())->contains('--target-mode must be');
        Assert::same(0, $runner->rectorInvocations(), 'A rejected run must never start Rector.');
    }

    #[Test]
    public function aMissingProcessedPathIsRejected(): void
    {
        [$runner, $dir, $report] = $this->probe();

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir . '/Missing'],
            '--report' => $report,
        ]);

        Assert::same(1, $result->exitCode());
        Assert::string($result->output())->contains('does not exist');
        Assert::same(0, $runner->rectorInvocations());
    }

    #[Test]
    public function aGitFailureBlocksApply(): void
    {
        [$runner, $dir, $report] = $this->probe(
            gitProbe: new ProcessOutcome(128, '', 'fatal: not a git repository'),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
            '--apply' => true,
        ]);

        Assert::same(1, $result->exitCode());
        Assert::string($result->output())->contains('Unable to verify a clean state with Git');
        Assert::string($result->output())->contains('fatal: not a git repository');
        Assert::same(0, $runner->rectorInvocations());
    }

    #[Test]
    public function anUnavailableGitBinaryFailsApplyFriendly(): void
    {
        [$runner, $dir, $report] = $this->probe(
            gitProbe: new ProcessOutcome(1, '', 'Failed to start git: git: command not found'),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
            '--apply' => true,
        ]);

        Assert::same(1, $result->exitCode());
        Assert::string($result->output())->contains('Unable to verify a clean state with Git');
        Assert::string($result->output())->contains('Failed to start git');
        Assert::same(0, $runner->rectorInvocations());
    }

    /**
     * The user-facing command boundary: when the Rector process cannot start at all
     * (the {@see \Laratesto\Rector\Console\SymfonyProcessRunner} start-failure shape),
     * the command surfaces the reason, exits 1 and writes no report.
     */
    #[Test]
    public function aRectorStartFailureFailsFriendlyAtTheCommandBoundary(): void
    {
        [$runner, $dir, $report] = $this->probe(
            rector: new ProcessOutcome(1, '', 'Failed to start php: the pinned rector binary is not executable'),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(1, $result->exitCode());
        Assert::string($result->output())->contains('Failed to start php');
        Assert::string($result->output())->contains('Rector failed with exit code 1');
        Assert::false(\is_file($report), 'No report is written when Rector cannot start.');
        Assert::same(1, $runner->rectorInvocations(), 'The start failure must have reached the runner exactly once.');
    }

    #[Test]
    public function untrackedProcessedPathsAllowApply(): void
    {
        [$runner, $dir, $report] = $this->probe(
            rector: new ProcessOutcome(0, \json_encode(['totals' => ['errors' => 0], 'file_diffs' => []]), ''),
            gitStatus: new ProcessOutcome(0, "?? storage/framework/testing/x.php\0", ''),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
            '--apply' => true,
        ]);

        Assert::same(0, $result->exitCode(), 'Untracked files are fresh input, not a rollback hazard.');
        // Focused guard-pass proof: the initial check AND the pre-Rector recheck each
        // probe the work tree and consult status once, then exactly one Rector run.
        Assert::same(2, $runner->gitProbeInvocations(), 'Apply must check the work tree twice (initial guard + pre-Rector recheck).');
        Assert::same(2, $runner->gitStatusInvocations(), 'Apply must consult status twice (initial guard + pre-Rector recheck).');
        Assert::same(1, $runner->rectorInvocations());
    }

    #[Test]
    public function modifiedProcessedPathsBlockApply(): void
    {
        [$runner, $dir, $report] = $this->probe(
            gitStatus: new ProcessOutcome(0, " M tests/Feature/Demo.php\0", ''),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
            '--apply' => true,
        ]);

        Assert::same(1, $result->exitCode());
        Assert::string($result->output())->contains('Refusing --apply');
        Assert::same(1, $runner->gitProbeInvocations(), 'The first guard pass must probe the work tree once.');
        Assert::same(1, $runner->gitStatusInvocations(), 'The first guard pass must consult status once.');
        Assert::same(0, $runner->rectorInvocations(), 'A blocked run must never start Rector.');
    }

    #[Test]
    public function dryRunAcceptsTheChangesFoundExitCode(): void
    {
        [$runner, $dir, $report] = $this->probe(
            rector: new ProcessOutcome(2, \json_encode(['totals' => ['errors' => 0], 'file_diffs' => []]), ''),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(0, $result->exitCode(), 'A dry-run with no residuals exits 0 even when Rector found changes.');
        Assert::same(0, $runner->gitProbeInvocations(), 'A dry-run never consults the Git guard.');
        Assert::same(0, $runner->gitStatusInvocations(), 'A dry-run never consults the Git guard.');
        Assert::same(1, $runner->rectorInvocations());
    }

    #[Test]
    public function applyRejectsTheChangesFoundExitCode(): void
    {
        [$runner, $dir, $report] = $this->probe(
            rector: new ProcessOutcome(2, \json_encode(['totals' => ['errors' => 0], 'file_diffs' => []]), ''),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
            '--apply' => true,
        ]);

        Assert::same(1, $result->exitCode(), 'An apply run must never accept the dry-run changes-found exit code.');
        Assert::string($result->output())->contains('failed with exit code 2');
    }

    #[Test]
    public function anyOtherRectorExitCodeFails(): void
    {
        [$runner, $dir, $report] = $this->probe(
            rector: new ProcessOutcome(3, \json_encode(['totals' => ['errors' => 0], 'file_diffs' => []]), ''),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(1, $result->exitCode());
        Assert::string($result->output())->contains('failed with exit code 3');
        Assert::same(1, $runner->rectorInvocations());
    }

    #[Test]
    public function malformedJsonPreservesThePreviousReport(): void
    {
        [$runner, $dir, $report] = $this->probe(
            rector: new ProcessOutcome(0, 'not json at all', ''),
        );

        \file_put_contents($report, '{"previous": true}' . "\n");

        try {
            $result = $this->artisan('laratesto:migrate-rector', [
                '--path' => [$dir],
                '--report' => $report,
            ]);

            Assert::same(1, $result->exitCode());
            Assert::string($result->output())->contains('malformed machine JSON');
            Assert::same(
                '{"previous": true}' . "\n",
                (string) \file_get_contents($report),
                'A failed run must not replace the previous report.',
            );
        } finally {
            @\unlink($report);
        }
    }

    #[Test]
    public function aPayloadWithoutTotalsIsAFailure(): void
    {
        [$runner, $dir, $report] = $this->probe(
            rector: new ProcessOutcome(0, '{"file_diffs": []}', ''),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(1, $result->exitCode());
        Assert::string($result->output())->contains('missing the required totals block');
    }

    #[Test]
    public function rectorProcessingErrorsAreAFailure(): void
    {
        [$runner, $dir, $report] = $this->probe(
            rector: new ProcessOutcome(0, \json_encode(['totals' => ['errors' => 2], 'file_diffs' => []]), ''),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(1, $result->exitCode());
        Assert::string($result->output())->contains('reported processing errors');
        Assert::false(\is_file($report), 'No report is written for a failed run.');
    }

    #[Test]
    public function aFailedRunSurfacesTheMachineJsonErrorDiagnostics(): void
    {
        [, $dir, $report] = $this->probe(
            rector: new ProcessOutcome(1, \json_encode([
                'totals' => ['changed_files' => 0, 'errors' => 1],
                'errors' => [
                    ['message' => 'Syntax error, unexpected identifier "strinng" on line 5.', 'file' => 'tests/Feature/BrokenTest.php', 'line' => 5],
                ],
            ]), ''),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(1, $result->exitCode());
        // The pinned Rector prints its JSON diagnostics (incl. the per-file
        // errors[]) to stdout BEFORE resolving the exit code — the failure
        // path must echo them instead of pointing at an empty "output above".
        Assert::string($result->output())->contains('Syntax error, unexpected identifier "strinng" on line 5.');
        Assert::string($result->output())->contains('tests/Feature/BrokenTest.php:5');
        Assert::string($result->output())->contains('Rector failed with exit code 1');
        Assert::string($result->output())->notContains('git restore', 'A dry-run never rewrites files, so no rollback hint may appear.');
        Assert::false(\is_file($report), 'No report is written for a failed run.');
    }

    #[Test]
    public function rectorProcessingErrorsSurfaceTheirPerFileDiagnostics(): void
    {
        [, $dir, $report] = $this->probe(
            rector: new ProcessOutcome(0, \json_encode([
                'totals' => ['changed_files' => 0, 'errors' => 1],
                'errors' => [
                    ['message' => 'Could not process the file: unterminated string literal.', 'file' => 'tests/Unit/HalfBrokenTest.php', 'line' => 12],
                ],
            ]), ''),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(1, $result->exitCode());
        // The errors>0 abort claims "see the output above" — the errors[] block
        // must BE that output, not a dangling promise.
        Assert::string($result->output())->contains('Could not process the file: unterminated string literal.');
        Assert::string($result->output())->contains('tests/Unit/HalfBrokenTest.php:12');
        Assert::string($result->output())->contains('reported processing errors');
        Assert::false(\is_file($report), 'No report is written for a failed run.');
    }

    #[Test]
    public function aFailedApplyHintsAtTheScopedRollback(): void
    {
        [, $dir, $report] = $this->probe(
            rector: new ProcessOutcome(1, \json_encode([
                'totals' => ['changed_files' => 2, 'errors' => 1],
                'errors' => [
                    ['message' => 'The process crashed after rewriting files in place.', 'file' => 'tests/Feature/BrokenTest.php', 'line' => 5],
                ],
            ]), ''),
        );

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
            '--apply' => true,
        ]);

        Assert::same(1, $result->exitCode());
        // A failed --apply can leave a partially rewritten tree — the failure
        // path must name the documented scoped rollback for the processed paths.
        Assert::string($result->output())->contains('git restore --source=HEAD -- storage/framework/testing/laratesto-guards-');
        Assert::string($result->output())->contains('Rector failed with exit code 1');
    }

    #[Test]
    public function anUnapplicableDiffFailsFriendlyInsteadOfEscaping(): void
    {
        [, $dir, $report] = $this->probe();

        // The rector outcome depends on the throwaway corpus path, so it is bound
        // after probe() created it: a machine diff the reconstructor cannot apply
        // (no hunks) used to let its RuntimeException escape handle() uncaught.
        $this->app()->bind(ProcessRunner::class, static fn(): ProcessRunner => new FakeProcessRunner(
            new ProcessOutcome(2, \json_encode([
                'totals' => ['changed_files' => 1, 'errors' => 0],
                'file_diffs' => [
                    ['file' => $dir . '/GuardProbeTest.php', 'diff' => 'not a unified diff'],
                ],
            ]), ''),
        ));

        $result = $this->artisan('laratesto:migrate-rector', [
            '--path' => [$dir],
            '--report' => $report,
        ]);

        Assert::same(1, $result->exitCode());
        Assert::string($result->output())->contains('The residuals scan failed');
        Assert::string($result->output())->contains('The diff contains no hunks to apply.');
        Assert::false(\is_file($report), 'No report is written for a failed run.');
    }

    /**
     * @return array{FakeProcessRunner, non-empty-string, non-empty-string}
     */
    private function probe(
        ?ProcessOutcome $rector = null,
        ?ProcessOutcome $gitProbe = null,
        ?ProcessOutcome $gitStatus = null,
    ): array {
        $root = $this->app()->basePath();
        $dir = $root . '/storage/framework/testing/laratesto-guards-' . \uniqid();

        \mkdir($dir, 0777, true);
        \file_put_contents($dir . '/GuardProbeTest.php', self::CORPUS);

        $report = $root . '/storage/framework/testing/laratesto-guards-report-' . \uniqid() . '.json';

        $runner = new FakeProcessRunner($rector, $gitProbe ?? new ProcessOutcome(0, "true\n", ''), $gitStatus ?? new ProcessOutcome(0, '', ''));

        // Blocked or failed runs legitimately leave the corpus and report behind —
        // remove them when the process ends, so the fixture tree stays clean.
        \register_shutdown_function(static function () use ($dir, $report): void {
            foreach (\glob($dir . '/*.php') ?: [] as $probeFile) {
                @\unlink($probeFile);
            }

            @\rmdir($dir);
            @\unlink($report);
        });

        $this->app()->bind(ProcessRunner::class, static fn(): ProcessRunner => $runner);
        $this->app()->register(LaratestoRectorServiceProvider::class);

        return [$runner, $dir, $report];
    }
}
