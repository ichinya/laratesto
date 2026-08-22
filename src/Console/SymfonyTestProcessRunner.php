<?php

declare(strict_types=1);

namespace Laratesto\Console;

use Symfony\Component\Process\Process;

/**
 * Runs test binaries without a shell so arguments stay portable and literal.
 */
final class SymfonyTestProcessRunner implements TestProcessRunner
{
    public function run(array $command, string $workingDirectory, \Closure $output): int
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout(null);

        return $process->run(
            static function (string $type, string $buffer) use ($output): void {
                $output($buffer, $type === Process::ERR);
            },
        );
    }
}
