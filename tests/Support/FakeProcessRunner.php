<?php

declare(strict_types=1);

namespace Laratesto\Tests\Support;

use Laratesto\Rector\Console\ProcessOutcome;
use Laratesto\Rector\Console\ProcessRunner;

/**
 * Canned {@see ProcessRunner} for the migrate-rector guard tests: Git probes and the
 * Rector process are answered from configured outcomes, every invocation recorded.
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

        if (($command[0] ?? '') === 'git') {
            return \str_contains($command[3] ?? '', 'rev-parse') ? $this->gitProbe : $this->gitStatus;
        }

        return $this->rectorOutcome ?? new ProcessOutcome(0, '', '');
    }

    public function rectorInvocations(): int
    {
        return \count(\array_filter(
            $this->invocations,
            static fn(array $invocation): bool => ($invocation['command'][2] ?? '') === 'process',
        ));
    }
}
