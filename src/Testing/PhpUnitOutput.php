<?php

declare(strict_types=1);

namespace Laratesto\Testing;

/** @internal Per-test output expectation, including setup but excluding teardown. */
final class PhpUnitOutput
{
    private ?int $level = null;
    public ?string $expected = null;

    public function start(): void
    {
        $this->level = ob_get_level();
        ob_start();
    }

    public function finish(bool $verify): void
    {
        if ($this->level === null) {
            return;
        }
        if (ob_get_level() !== $this->level + 1) {
            throw new \RuntimeException('The test changed the output buffer stack without restoring it.');
        }
        $output = (string) ob_get_clean();
        $this->level = null;
        if ($verify && $this->expected !== null) {
            \Testo\Assert::same($output, $this->expected, 'The test output must match expectOutputString() exactly.');
        } else {
            echo $output;
        }
    }

    public function close(): void
    {
        if ($this->level === null) {
            return;
        }
        $level = $this->level;
        $this->level = null;
        $failure = null;
        while (ob_get_level() > $level) {
            $before = ob_get_level();
            if ((ob_get_status()['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) {
                $failure ??= new \RuntimeException('The test left an output buffer that cannot be removed.');
                break;
            }
            try {
                ob_end_flush();
            } catch (\Throwable $exception) {
                // PHP removes the buffer even when its handler throws. Continue
                // closing our capture, then report the first cleanup failure.
                $failure ??= $exception;
            }
            if (ob_get_level() >= $before) {
                $failure ??= new \RuntimeException('The test left an output buffer that cannot be removed.');
                break;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
