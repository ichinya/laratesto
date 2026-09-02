<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

/**
 * Outcome of one external process invocation, decoupled from Symfony's Process so
 * tests can inject canned results instead of real subprocesses.
 */
final readonly class ProcessOutcome
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}
}
