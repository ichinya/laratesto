<?php

declare(strict_types=1);

namespace Laratesto\Tests\Support;

use Laratesto\Console\TestProcessRunner;

final class FakeTestProcessRunner implements TestProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<string> */
    public array $workingDirectories = [];

    /** @param list<int> $exitCodes */
    public function __construct(
        private array $exitCodes = [0],
    ) {
    }

    public function run(array $command, string $workingDirectory, \Closure $output): int
    {
        $this->commands[] = $command;
        $this->workingDirectories[] = $workingDirectory;
        $output('fake runner output' . \PHP_EOL, false);

        return \array_shift($this->exitCodes) ?? 0;
    }
}
