<?php

declare(strict_types=1);

namespace Laratesto\Pipeline\Internal;

use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Value\Status;

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
    public static function aborted(TestInfo $info, \Throwable $failure): TestResult
    {
        return new TestResult(
            info: $info,
            status: Status::Aborted,
            failure: $failure,
        );
    }
}
