<?php

declare(strict_types=1);

namespace Laratesto\Testing;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Testo\Assert;

/**
 * Result of an Artisan command execution with Testo-native assertions,
 * mirroring Laravel's `PendingCommand` for the assertions that matter.
 *
 * The command runs eagerly; the returned object holds its exit code and
 * captured output.
 *
 * @api
 */
final class PendingArtisanCommand
{
    private readonly int $exitCode;

    private readonly string $output;

    public function __construct(
        private readonly ConsoleKernel $kernel,
        private readonly string $command,
        private readonly array $parameters,
    ) {
        $this->exitCode = $this->kernel->call($this->command, $this->parameters);
        $this->output = $this->kernel->output();
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function output(): string
    {
        return $this->output;
    }

    /**
     * Assert the command exited with the given code.
     */
    public function assertExitCode(int $code): static
    {
        Assert::same($this->exitCode, $code, \sprintf(
            'Expected exit code %d from [artisan %s], got %d. Output: "%s".',
            $code,
            $this->command,
            $this->exitCode,
            \mb_substr($this->output, 0, 255),
        ));

        return $this;
    }

    /**
     * Assert the command exited successfully (code 0).
     */
    public function assertSuccessful(): static
    {
        return $this->assertExitCode(0);
    }

    /**
     * Assert the command output contains the given text.
     */
    public function expectsOutputToContain(string $text): static
    {
        Assert::true(
            \str_contains($this->output, $text),
            \sprintf(
                'Expected [artisan %s] output to contain "%s". Actual output: "%s".',
                $this->command,
                $text,
                \mb_substr($this->output, 0, 255),
            ),
        );

        return $this;
    }

    /**
     * Assert the command output does not contain the given text.
     */
    public function doesntExpectOutputToContain(string $text): static
    {
        Assert::false(
            \str_contains($this->output, $text),
            \sprintf(
                'Expected [artisan %s] output not to contain "%s".',
                $this->command,
                $text,
            ),
        );

        return $this;
    }

    /**
     * Assert the command output has a line matching the given text (trimmed),
     * mirroring Laravel's `expectsOutput`.
     */
    public function expectsOutput(string $text): static
    {
        $lines = \array_map('trim', \explode("\n", $this->output));

        Assert::true(
            \in_array($text, $lines, true),
            \sprintf(
                'Expected [artisan %s] output line "%s". Actual output: "%s".',
                $this->command,
                $text,
                \mb_substr($this->output, 0, 255),
            ),
        );

        return $this;
    }
}
