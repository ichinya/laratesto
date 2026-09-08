<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Testing\PhpUnitOutput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class PhpUnitOutputTest
{
    #[Test]
    public function throwingOutputHandlerStillClosesTheCaptureBuffer(): void
    {
        $level = ob_get_level();
        $capture = new PhpUnitOutput();
        $failure = new \RuntimeException('output handler failed');
        $caught = null;
        $remainingLevel = null;
        try {
            $capture->start();
            ob_start(static function () use ($failure): string { throw $failure; });
            try {
                $capture->close();
            } catch (\Throwable $exception) {
                $caught = $exception;
            }
            $remainingLevel = ob_get_level();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        Assert::same($failure, $caught);
        Assert::same($level, $remainingLevel, 'Cleanup must continue after a removable handler throws.');
    }

    #[Test]
    public function nonRemovableOutputBufferCannotHangCleanup(): void
    {
        $file = var_export(dirname(__DIR__, 2).'/src/Testing/PhpUnitOutput.php', true);
        $code = 'require '.$file.'; error_reporting(0); $capture = new \\Laratesto\\Testing\\PhpUnitOutput(); $capture->start(); '
            .'ob_start(null, 0, PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_REMOVABLE); '
            .'try { $capture->close(); } catch (\\RuntimeException $failure) { fwrite(STDERR, $failure->getMessage()); exit(0); } exit(1);';
        $process = new Process([PHP_BINARY, '-r', $code], timeout: 3);
        $finished = true;
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $finished = false;
        }
        Assert::true($finished, 'A non-removable buffer must fail promptly instead of looping.');
        Assert::same(0, $process->getExitCode());
        Assert::string($process->getErrorOutput())->contains('cannot be removed');
    }
}
