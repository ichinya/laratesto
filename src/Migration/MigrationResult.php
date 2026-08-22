<?php

declare(strict_types=1);

namespace Laratesto\Migration;

/**
 * Result of converting one PHPUnit source file.
 *
 * @internal The migration API is intentionally conservative and may evolve while
 *           Testo's source format is still stabilizing.
 */
final readonly class MigrationResult
{
    /**
     * @param list<string> $warnings
     * @param list<string> $errors
     */
    public function __construct(
        public string $code,
        public array $warnings = [],
        public array $errors = [],
    ) {}

    public function successful(): bool
    {
        return $this->errors === [];
    }
}
