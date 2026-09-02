<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

use Symfony\Component\Process\Process;

/**
 * The production {@see ProcessRunner}: argument-array invocation through Symfony's
 * Process — the command never reaches a shell, so no platform-specific escaping.
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, string $workingDirectory): ProcessOutcome
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout(null);
        $process->run();

        return new ProcessOutcome(
            $process->getExitCode() ?? 1,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }
}
