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
        Assert::string($result->output())->contains('not inside a Git work tree');
        Assert::same(0, $runner->rectorInvocations());
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

        $this->app()->bind(ProcessRunner::class, static fn(): ProcessRunner => $runner);
        $this->app()->register(LaratestoRectorServiceProvider::class);

        return [$runner, $dir, $report];
    }
}
