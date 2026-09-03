<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Console;

use Laratesto\Rector\Console\SymfonyProcessRunner;
use Testo\Assert;
use Testo\Test;

/**
 * The bounded process runner (PR #8 review M2): normal exits stay plain outcomes,
 * a missing executable becomes a failed outcome (friendly exit 1) instead of a raw
 * Symfony Process exception, and a run exceeding the documented timeout is killed
 * and reported with its partial output — never an unbounded hang.
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

        // Windows resolves the command through cmd.exe (exit 1 with a message naming
        // the binary); POSIX fails proc_open and Symfony throws a start failure —
        // both routes must end in the same friendly failed outcome, never a throw.
        Assert::same($outcome->exitCode, 1);
        Assert::same($outcome->stdout, '');
        Assert::true(\str_contains($outcome->stderr, 'laratesto-not-a-real-binary-xyz'), $outcome->stderr);
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
