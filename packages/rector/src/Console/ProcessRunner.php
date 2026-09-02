<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

/**
 * Runs one argument-array command in a working directory — no shell, so no escaping
 * concerns on any platform. The only implementation in production is
 * {@see SymfonyProcessRunner}; tests inject canned outcomes.
 */
interface ProcessRunner
{
    public function run(array $command, string $workingDirectory): ProcessOutcome;
}
