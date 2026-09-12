<?php

declare(strict_types=1);

namespace PHPUnit\Metadata;

/**
 * Stub for PHPUnit metadata value objects.
 */
abstract class Metadata
{
    public function isDisableReturnValueGenerationForTestDoubles(): bool
    {
        return false;
    }
}
