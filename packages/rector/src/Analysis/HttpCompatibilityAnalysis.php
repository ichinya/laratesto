<?php

declare(strict_types=1);

namespace Laratesto\Rector\Analysis;

/** @internal Whole-class HTTP/TestResponse signature preflight result. */
final readonly class HttpCompatibilityAnalysis
{
    /** @param array<non-empty-string, list<non-empty-string>> $reasonsByCode */
    public function __construct(
        public array $reasonsByCode = [],
        public bool $hasTestResponseType = false,
    ) {}

    public function safe(): bool
    {
        return $this->reasonsByCode === [];
    }
}
