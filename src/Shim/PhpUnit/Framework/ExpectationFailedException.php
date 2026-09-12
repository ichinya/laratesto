<?php

declare(strict_types=1);

namespace PHPUnit\Framework;

/**
 * Shim for PHPUnit's expectation failure exception.
 *
 * This class is only used when phpunit/phpunit is not installed.
 */
final class ExpectationFailedException extends AssertionFailedError
{
}
