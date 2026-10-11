<?php

declare(strict_types=1);

namespace LaratestoConsumer;

final class ProbeMath
{
    public static function magnitude(int $value): int
    {
        if ($value < 0) {
            return -$value;
        }
        return $value;
    }
}
