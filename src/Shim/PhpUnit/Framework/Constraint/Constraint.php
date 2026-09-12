<?php

declare(strict_types=1);

namespace PHPUnit\Framework\Constraint;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\SelfDescribing;
use SebastianBergmann\Comparator\ComparisonFailure;

/**
 * Minimal PHPUnit constraint shim.
 */
abstract class Constraint implements \Countable, SelfDescribing
{
    public function count(): int
    {
        return 1;
    }

    public function evaluate(mixed $other, string $description = '', bool $returnResult = false): ?bool
    {
        $success = $this->matches($other);

        if ($returnResult) {
            return $success;
        }

        if (!$success) {
            $this->fail($other, $description);
        }

        return null;
    }

    protected function matches(mixed $other): bool
    {
        return false;
    }

    protected function fail(mixed $other, string $description = '', ?ComparisonFailure $comparisonFailure = null): never
    {
        $message = \sprintf(
            'Failed asserting that %s%s',
            $this->failureDescription($other),
            $description !== '' ? "\n{$description}" : '',
        );

        throw new ExpectationFailedException($message);
    }

    protected function failureDescription(mixed $other): string
    {
        return \var_export($other, true) . ' ' . $this->toString();
    }

    public function toString(): string
    {
        return '';
    }
}
