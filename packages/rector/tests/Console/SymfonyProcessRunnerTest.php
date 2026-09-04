<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Console;

use Laratesto\Rector\Console\SymfonyProcessRunner;
use Testo\Assert;
use Testo\Test;

/**
 * The bounded process runner (PR #8 review M2): normal exits stay plain outcomes,
 * a missing executable becomes a failed outcome naming the binary instead of a raw
 * Symfony Process exception (the exact exit code is platform-shaped: the Windows
 * start failure reports 1, the POSIX shell reports its "command not found" 127),
 * and a run exceeding the documented timeout is killed and reported with its
 * partial output — never an unbounded hang.
 */
final class SymfonyProcessRunnerTest
{
    #[Test]
    public function theHappyPathPassesThroughOutputAndExitCode(): void
    {
        $runner = new SymfonyProcessRunner();

        $outcome = $runner->run(
            [
                \PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(3);',
            ],
            __DIR__,
        );

        Assert::same($outcome->exitCode, 3);
        Assert::same($outcome->stdout, 'out');
        Assert::same($outcome->stderr, 'err');
    }

    #[Test]
    public function aMissingBinaryBecomesAFailedOutcomeInsteadOfAnException(): void
    {
        $runner = new SymfonyProcessRunner();

        $outcome = $runner->run(['laratesto-not-a-real-binary-xyz', '--version'], __DIR__);

        // The exit code is platform-shaped, not part of the contract: Windows fails
        // proc_open and Symfony throws a start failure the runner maps to exit 1;
        // POSIX re-runs the array command through /bin/sh ("exec ...") whose shell
        // reports the missing binary itself with exit 127 and a diagnostic on stderr.
        // The cross-platform contract is: failed non-zero outcome, no stdout, and the
        // missing binary named in stderr — never a raw Symfony exception.
        Assert::notSame(0, $outcome->exitCode, "a missing binary must produce a failed outcome, got exit code {$outcome->exitCode}");
        Assert::same($outcome->stdout, '');
        Assert::true(\str_contains($outcome->stderr, 'laratesto-not-a-real-binary-xyz'), $outcome->stderr);
    }

    #[Test]
    public function aChildThatRunsAndChoosesExit127KeepsItsOwnOutput(): void
    {
        $runner = new SymfonyProcessRunner();

        // A raw child exit code is the caller's information: a child that really ran
        // and chose the shell's "command not found" code passes its code and both
        // streams through untouched — the runner never rewrites genuine results.
        $outcome = $runner->run(
            [
                \PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(127);',
            ],
            __DIR__,
        );

        Assert::same($outcome->exitCode, 127);
        Assert::same($outcome->stdout, 'out');
        Assert::same($outcome->stderr, 'err');
    }

    #[Test]
    public function aProcessStartFailureBecomesAFailedOutcomeInsteadOfAnException(): void
    {
        $runner = new SymfonyProcessRunner();

        // A nonexistent working directory fails the start on every platform — the
        // deterministic cross-platform "process cannot start" case.
        $outcome = $runner->run([\PHP_BINARY, '-r', 'echo "hi";'], __DIR__ . '/does-not-exist');

        Assert::same($outcome->exitCode, 1);
        Assert::same($outcome->stdout, '');
        Assert::true(\str_contains($outcome->stderr, 'does not exist'), $outcome->stderr);
    }

    #[Test]
    public function aTimedOutProcessIsKilledAndReportedAsAFailure(): void
    {
        $runner = new SymfonyProcessRunner(0.1);

        $outcome = $runner->run(
            [
                \PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "partial"); usleep(500000);',
            ],
            __DIR__,
        );

        Assert::same($outcome->exitCode, 1);
        Assert::same($outcome->stdout, 'partial');
        Assert::true(\str_contains($outcome->stderr, 'exceeded the timeout'), $outcome->stderr);
    }
}
