<?php

declare(strict_types=1);

namespace Laratesto\Testing;

/** Preserve Laravel's deferred execution and its own console assertions. */
final class DeferredArtisanCommand
{
    public function __construct(private readonly \Illuminate\Testing\PendingCommand $pending) {}

    public function assertExitCode($exitCode): static
    {
        $this->pending->assertExitCode($exitCode);
        return $this;
    }

    public function assertSuccessful(): static
    {
        $this->pending->assertSuccessful();
        return $this;
    }

    public function assertFailed(): static
    {
        $this->pending->assertFailed();
        return $this;
    }

    public function expectsOutput($output = null): static
    {
        $this->pending->expectsOutput($output);
        return $this;
    }

    public function expectsOutputToContain($string): static
    {
        $this->pending->expectsOutputToContain($string);
        return $this;
    }

    public function doesntExpectOutputToContain($string): static
    {
        $this->pending->doesntExpectOutputToContain($string);
        return $this;
    }

    public function run(): int
    {
        return PhpUnitCompatibility::run(fn(): int => $this->pending->run());
    }

    public function execute(): int
    {
        return $this->run();
    }

    public function __destruct()
    {
        // Laravel sets hasExecuted before running, including on failure. Its
        // subsequent natural destructor therefore cannot execute a second time.
        PhpUnitCompatibility::run(fn() => $this->pending->__destruct());
    }
}
