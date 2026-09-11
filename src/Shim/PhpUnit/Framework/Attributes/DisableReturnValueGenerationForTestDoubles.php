<?php

declare(strict_types=1);

namespace PHPUnit\Framework\Attributes;

use PHPUnit\Metadata\Metadata;

/**
 * Shim for PHPUnit's test-double attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DisableReturnValueGenerationForTestDoubles extends Metadata
{
    public function isDisableReturnValueGenerationForTestDoubles(): bool
    {
        return true;
    }
}
