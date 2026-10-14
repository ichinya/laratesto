<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Coverage;

final class Ignored
{
    /** @codeCoverageIgnore */
    public static function value(): int
    {
        return -1;
    }
}
