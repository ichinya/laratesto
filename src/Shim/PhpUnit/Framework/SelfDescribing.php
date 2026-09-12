<?php

declare(strict_types=1);

namespace PHPUnit\Framework;

/**
 * Shim for PHPUnit's self-describing interface.
 */
interface SelfDescribing
{
    public function toString(): string;
}
