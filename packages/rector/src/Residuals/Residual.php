<?php

declare(strict_types=1);

namespace Laratesto\Rector\Residuals;

/**
 * One residual finding: a marker occurrence in a migrated file.
 *
 * @api
 */
final readonly class Residual
{
    public function __construct(
        public string $file,
        public int $line,
        public string $rule,
        public string $reason,
    ) {}

    /**
     * @return array{file: string, line: int, rule: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'rule' => $this->rule,
            'reason' => $this->reason,
        ];
    }
}
