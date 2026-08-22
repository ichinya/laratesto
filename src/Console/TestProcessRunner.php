<?php

declare(strict_types=1);

namespace Laratesto\Console;

/**
 * Executes one test runner while streaming its output back to Artisan.
 */
interface TestProcessRunner
{
    /**
     * @param list<string> $command
     * @param \Closure(string, bool): void $output
     */
    public function run(array $command, string $workingDirectory, \Closure $output): int;
}
