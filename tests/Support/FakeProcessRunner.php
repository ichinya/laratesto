<?php

declare(strict_types=1);

namespace Laratesto\Tests\Support;

use Laratesto\Rector\Console\ProcessOutcome;
use Laratesto\Rector\Console\ProcessRunner;

/**
 * Canned {@see ProcessRunner} for the migrate-rector guard tests: every invocation
 * is recorded, and Git probes/status runs are classified semantically by their
 * subcommand token (`rev-parse` / `status`), never by a positional argv index —
 * the remaining invocations are the Rector `process` runs.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, cwd: string}> */
    public array $invocations = [];

    public function __construct(
        private readonly ?ProcessOutcome $rectorOutcome = null,
        private readonly ProcessOutcome $gitProbe = new ProcessOutcome(0, "true\n", ''),
        private readonly ProcessOutcome $gitStatus = new ProcessOutcome(0, '', ''),
    ) {}

    public function run(array $command, string $workingDirectory): ProcessOutcome
    {
        $this->invocations[] = ['command' => $command, 'cwd' => $workingDirectory];

        if (\in_array('rev-parse', $command, true)) {
            return $this->gitProbe;
        }

        if (\in_array('status', $command, true)) {
            return $this->gitStatus;
        }

        return $this->rectorOutcome ?? new ProcessOutcome(0, '', '');
    }

    public function rectorInvocations(): int
    {
        return $this->classified('process');
    }

    public function gitProbeInvocations(): int
    {
        return $this->classified('rev-parse');
    }

    public function gitStatusInvocations(): int
    {
        return $this->classified('status');
    }

    private function classified(string $subcommand): int
    {
        return \count(\array_filter(
            $this->invocations,
            static fn(array $invocation): bool => \in_array($subcommand, $invocation['command'], true),
        ));
    }
}
