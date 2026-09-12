<?php

declare(strict_types=1);

namespace PHPUnit\Framework;

/**
 * Shim for PHPUnit's assertion failure exception.
 *
 * This class is only used when phpunit/phpunit is not installed.
 */
class AssertionFailedError extends \RuntimeException
{
    public function toString(): string
    {
        return $this->getMessage();
    }
}
