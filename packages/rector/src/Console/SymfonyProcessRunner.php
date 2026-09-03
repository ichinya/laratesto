<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * The production {@see ProcessRunner}: argument-array invocation through Symfony's
 * Process — the command never reaches a shell, so no platform-specific escaping.
 *
 * Every run is bounded: a child must finish within {@see self::DEFAULT_TIMEOUT_SECONDS}
 * (constructor override), after which Symfony kills it and the run reports a failure
 * instead of hanging the command forever. A child that cannot start (the executable is
 * missing — e.g. Git not installed) or that dies by signal never throws out of here:
 * it becomes a failed {@see ProcessOutcome} with the reason in stderr, so callers fail
 * closed with friendly diagnostics.
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    /**
     * The runtime bound for every external process (Git guard calls, the Rector run):
     * generous enough for a pinned-Rector pass over a large test tree, tight enough
     * that a hung child cannot stall the command indefinitely.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 600.0;

    public function __construct(
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    public function run(array $command, string $workingDirectory): ProcessOutcome
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessStartFailedException $failure) {
            // The child never ran: no output exists, the reason lives in the message.
            return new ProcessOutcome(1, '', \sprintf(
                'Failed to start %s: %s',
                $command[0] ?? 'the external process',
                \trim($failure->getMessage()),
            ));
        } catch (ProcessException $failure) {
            // The child ran and was killed (timeout, signal): keep its partial output.
            // A start failure that bypasses ProcessStartFailedException (e.g. a missing
            // working directory) never started, so there is no output to preserve.
            $started = $process->isStarted();

            return new ProcessOutcome(
                1,
                $started ? $process->getOutput() : '',
                \trim(\sprintf(
                    "%s\n%s",
                    $started ? \trim($process->getErrorOutput()) : '',
                    $failure->getMessage(),
                )),
            );
        }

        return new ProcessOutcome(
            $process->getExitCode() ?? 1,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }
}
