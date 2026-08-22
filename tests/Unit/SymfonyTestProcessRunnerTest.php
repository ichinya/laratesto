<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Console\SymfonyTestProcessRunner;
use Testo\Assert;
use Testo\Test;

final class SymfonyTestProcessRunnerTest
{
    #[Test]
    public function streamsOutputAndReturnsTheChildExitCode(): void
    {
        $output = [];
        $runner = new SymfonyTestProcessRunner();

        $exitCode = $runner->run(
            [
                \PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "standard"); fwrite(STDERR, "error"); exit(3);',
            ],
            __DIR__,
            static function (string $buffer, bool $error) use (&$output): void {
                $output[] = [$buffer, $error];
            },
        );

        Assert::same($exitCode, 3);
        Assert::same(\implode('', \array_column($output, 0)), 'standarderror');
        Assert::true(\in_array(['standard', false], $output, true));
        Assert::true(\in_array(['error', true], $output, true));
    }
}
