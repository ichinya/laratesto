<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Coverage;

final class NeverLoaded
{
    public static function value(): int
    {
        return 123;
    }
}
