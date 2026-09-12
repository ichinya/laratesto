<?php

declare(strict_types=1);

namespace PHPUnit\Metadata;

/**
 * @internal
 */
final class DisableReturnValueGenerationForTestDoubles extends Metadata
{
    public function isDisableReturnValueGenerationForTestDoubles(): bool
    {
        return true;
    }
}
