<?php

declare(strict_types=1);

namespace Laratesto\Pipeline\Internal;

use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Exception\CancelTest;
use Testo\Core\Exception\SkipTest;
use Testo\Core\Value\Status;
use Testo\Common\Messenger;
use Testo\Core\Log\Level;
use Testo\Core\Log\Message;
use Testo\Core\Log\MessageLog;

/**
 * Builds aborted test results that carry the original bridge failure.
 *
 * Returning a result instead of throwing is deliberate: Testo wraps any
 * interceptor exception into an opaque {@see \Testo\Application\Exception\PipelineFailure}
 * and hides the root cause in the failure output. A returned result with the
 * original failure renders like a normal test failure, with the real
 * exception class, message and trace.
 *
 * @internal
 */
final class FailureResult
{
    /** Preserve an existing terminal outcome and all payload/metrics across cleanup. */
    public static function afterCleanup(TestResult $result, \Throwable $failure, string $stage, ?Messenger $messenger = null, bool $lifecycle = false): TestResult
    {
        self::report($failure, $stage, $messenger);
        $messages = new MessageLog([...$result->messages->all(), new Message(microtime(true), Messenger::CHANNEL_STDERR, Level::Error, self::diagnostic($failure, $stage))]);
        $result = $result->withMessages($messages);
        if ($result->failure !== null || in_array($result->status, [Status::Failed, Status::Error, Status::Aborted, Status::Cancelled, Status::Skipped], true)) {
            return $result;
        }
        $status = $lifecycle ? self::lifecycleStatus($failure) : Status::Aborted;
        return $result->with(status: $status)->withFailure($failure)->withSummary(
            $result->summary->counts === [] ? $result->summary : $result->summary->withStatus($status),
        );
    }

    /** Diagnostic delivery is best effort and must never interrupt cleanup. */
    public static function report(\Throwable $failure, string $stage, ?Messenger $messenger = null): void
    {
        try {
            if ($messenger !== null) {
                $messenger->log(Messenger::CHANNEL_STDERR, self::diagnostic($failure, $stage), Level::Error);
                return;
            }
        } catch (\Throwable) {
            // Fall back to the process diagnostic stream if a listener/logger fails.
        }
        try {
            @fwrite(STDERR, self::diagnostic($failure, $stage).PHP_EOL);
        } catch (\Throwable) {}
    }

    private static function diagnostic(\Throwable $failure, string $stage): string
    {
        return '[laratesto] '.$stage.': '.$failure::class.': '.$failure->getMessage();
    }

    public static function trace(string $stage, ?Messenger $messenger = null): void
    {
        if (getenv('LARATESTO_DEBUG') !== '1') {
            return;
        }
        try {
            if ($messenger !== null) {
                $messenger->log(Messenger::CHANNEL_STDERR, '[laratesto debug] '.$stage, Level::Debug);
            } else {
                @fwrite(STDERR, '[laratesto debug] '.$stage.PHP_EOL);
            }
        } catch (\Throwable) {}
    }

    private static function lifecycleStatus(\Throwable $failure): Status
    {
        return match (true) {
            $failure instanceof SkipTest => Status::Skipped,
            $failure instanceof CancelTest => Status::Cancelled,
            $failure instanceof \Testo\Assert\State\Record => Status::Failed,
            default => Status::Aborted,
        };
    }
    public static function aborted(TestInfo $info, \Throwable $failure): TestResult
    {
        return new TestResult(
            info: $info,
            status: Status::Aborted,
            failure: $failure,
        );
    }

    /**
     * Classify exceptions from a user lifecycle hook exactly as Testo classifies
     * exceptions thrown from a test body. Other hook failures remain aborts.
     */
    public static function fromLifecycle(TestInfo $info, \Throwable $failure): TestResult
    {
        $status = self::lifecycleStatus($failure);

        return new TestResult(
            info: $info,
            status: $status,
            failure: $failure,
        );
    }
}
