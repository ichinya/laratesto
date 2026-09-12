<?php

declare(strict_types=1);

namespace SebastianBergmann\Exporter;

/**
 * Stub for the value exporter.
 */
class Exporter
{
    public function export(mixed $value): string
    {
        return \var_export($value, true);
    }
}
