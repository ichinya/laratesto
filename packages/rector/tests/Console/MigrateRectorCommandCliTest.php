<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Console;

use Illuminate\Foundation\Application;
use Laratesto\Rector\Console\MigrateRectorCommand;
use Laratesto\Rector\Console\ProcessOutcome;
use Testo\Core\Exception\SkipTest;
use Laratesto\Rector\Console\ProcessRunner;
use Laratesto\Rector\Console\RectorConfigWriter;
use Laratesto\Tests\Support\FakeProcessRunner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;

/**
 * The `laratesto:migrate-rector` command boundary: the real command object with its
 * real config writer, scanner, report writer and path guard over a real fixture-app
 * root — only the process runner is canned. The PR #8 review scenarios (unreadable
 * reads, untracked rollback gaps, the config exit contract) fail or pass HERE, at
 * the exit-code contract, not behind mocks.
 */
final class MigrateRectorCommandCliTest
{
    private const CORPUS = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Feature;

        final class ProbeTest extends \Illuminate\Foundation\Testing\TestCase
        {
            public function test_probe(): void {}
        }
        PHP;

    private const MARKER_CORPUS = <<<'PHP'
        <?php

        /* laratesto-residual(code=LARAVEL_FAKE_UNSUPPORTED, rule=LaravelResidualDetectionRector, severity=manual): Mail::fake() — no automatic conversion */
        final class MarkedProbeTest
        {
        }
        PHP;

    #[Test]
    public function anExclusivelyLockedProcessedFileFailsTheDryRunClearly(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            // flock() is advisory on POSIX: a read of the locked file there
            // succeeds, so the mandatory-lock regression is Windows-only.
            throw new SkipTest('Mandatory read blocking via flock() is a Windows behavior.');
        }

        $scratch = $this->scratch();
        $probe = $scratch['corpus'] . '/LockedProbeTest.php';
        \file_put_contents($probe, self::CORPUS);

        $lock = \fopen($probe, 'c+');
        Assert::true(\is_resource($lock));
        Assert::true(\flock($lock, \LOCK_EX));

        try {
            $this->assertFailedReadFailsTheDryRun($scratch, 'LockedProbeTest.php', 'Permission denied');
        } finally {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        }
    }

    #[Test]
    public function aPermissionDeniedProcessedFileFailsTheDryRunClearly(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            // Windows ignores chmod read bits for the owner; the locked-file twin
            // above carries the mandatory-blocking coverage on this platform.
            throw new SkipTest('POSIX permission bits are not enforced for the owner on Windows.');
        }

        $scratch = $this->scratch();
        $probe = $scratch['corpus'] . '/DeniedProbeTest.php';
        \file_put_contents($probe, self::CORPUS);
        \chmod($probe, 0000);

        try {
            if ((string) @\file_get_contents($probe) !== '') {
                throw new SkipTest('This platform does not enforce the deny-read permission.');
            }

            $this->assertFailedReadFailsTheDryRun($scratch, 'DeniedProbeTest.php', 'Permission denied');
        } finally {
            \chmod($probe, 0644);
        }
    }

    #[Test]
    public function aZeroByteProcessedFileIsLegitimateAndScansEmpty(): void
    {
        $scratch = $this->scratch();
        \file_put_contents($scratch['corpus'] . '/EmptyProbeTest.php', '');

        [$exit, $output] = $this->run(
            rector: new ProcessOutcome(0, \json_encode(['totals' => ['errors' => 0], 'file_diffs' => []]), ''),
            corpus: $scratch['corpus'],
            report: $scratch['report'],
        );

        Assert::same(0, $exit, 'A zero-byte PHP file is valid input — it must not fail the run.');
        Assert::string($output)->contains('No residuals');
        Assert::true(\is_file($scratch['report']));
    }

    /**
     * The shared unreadable-read contract: exit 1 with the named file, no report,
     * and the previously installed error handler still on top after the command
     * restored its scoped collector — a leaked handler would swallow the probe
     * warning and fail this assertion.
     *
     * @param array{corpus: non-empty-string, report: non-empty-string} $scratch
     */
    private function assertFailedReadFailsTheDryRun(array $scratch, string $file, string $expectedReason): void
    {
        $probeWarnings = [];

        // Mirror Laravel's HandleExceptions: diagnostics silenced with `@` carry
        // an emptied error_reporting mask and never escalate, so the probe only
        // records warnings a real handler would have seen.
        \set_error_handler(static function (int $number, string $message) use (&$probeWarnings): bool {
            if (\error_reporting() & $number) {
                $probeWarnings[] = $message;
            }

            return true;
        });

        try {
            [$exit, $output] = $this->run(
                rector: new ProcessOutcome(0, \json_encode(['totals' => ['errors' => 0], 'file_diffs' => []]), ''),
                corpus: $scratch['corpus'],
                report: $scratch['report'],
            );

            Assert::same(1, $exit, 'A failed read must exit 1, never scan the file as silently empty.');
            Assert::string($output)->contains('The residuals scan failed');
            Assert::string($output)->contains($file);
            Assert::string($output)->contains($expectedReason);
            Assert::false(\is_file($scratch['report']), 'No report is written for a failed scan.');

            \trigger_error('handler-restoration-probe', \E_USER_WARNING);

            Assert::same($probeWarnings, ['handler-restoration-probe'], 'The command must restore the prior error handler.');
        } finally {
            \restore_error_handler();
        }
    }

    #[Test]
    public function aReadableProcessedFileStillReportsItsResiduals(): void
    {
        $scratch = $this->scratch();
        \file_put_contents($scratch['corpus'] . '/MarkedProbeTest.php', self::MARKER_CORPUS);

        [$exit, $output] = $this->run(
            rector: new ProcessOutcome(0, \json_encode(['totals' => ['errors' => 0], 'file_diffs' => []]), ''),
            corpus: $scratch['corpus'],
            report: $scratch['report'],
        );

        Assert::same(2, $exit, 'The positive control: the readable corpus carries its marker into the report.');
        Assert::true(\is_file($scratch['report']));
        Assert::string((string) \file_get_contents($scratch['report']))->contains('LARAVEL_FAKE_UNSUPPORTED');
    }

    /**
     * Runs the real command over the fixture app root with a canned process runner.
     *
     * @return array{int, string}
     */
    private function run(
        ProcessOutcome $rector,
        string $corpus,
        string $report,
        ?ProcessOutcome $gitProbe = null,
        ?ProcessOutcome $gitStatus = null,
        ?RectorConfigWriter $configWriter = null,
        bool $apply = false,
    ): array {
        $root = \dirname(__DIR__, 4) . '/tests/Fixture/laravel';
        Assert::true(\is_dir($root), 'The fixture Laravel app must exist for the command harness.');

        $runner = new FakeProcessRunner(
            $rector,
            $gitProbe ?? new ProcessOutcome(0, "true\n", ''),
            $gitStatus ?? new ProcessOutcome(0, '', ''),
        );

        $command = new MigrateRectorCommand(
            runner: $runner,
            configWriter: $configWriter ?? new RectorConfigWriter(),
        );
        $command->setLaravel(new Application($root));

        $options = ['--path' => [$corpus], '--report' => $report];

        if ($apply) {
            $options['--apply'] = true;
        }

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput($options), $output);

        return [$exit, $output->fetch()];
    }

    /**
     * A throwaway corpus directory and report path inside the fixture app, cleaned
     * up when the process ends so failed runs cannot dirty the fixture tree.
     *
     * @return array{corpus: non-empty-string, report: non-empty-string}
     */
    private function scratch(): array
    {
        $root = \dirname(__DIR__, 4) . '/tests/Fixture/laravel';
        $corpus = $root . '/storage/framework/testing/laratesto-cli-' . \uniqid();
        Assert::true(\mkdir($corpus, 0777, true) || \is_dir($corpus));
        $report = $root . '/storage/framework/testing/laratesto-cli-report-' . \uniqid() . '.json';

        \register_shutdown_function(static function () use ($corpus, $report): void {
            foreach (\glob($corpus . '/*.php') ?: [] as $probeFile) {
                @\unlink($probeFile);
            }

            @\rmdir($corpus);
            @\unlink($report);
        });

        return ['corpus' => $corpus, 'report' => $report];
    }
}
