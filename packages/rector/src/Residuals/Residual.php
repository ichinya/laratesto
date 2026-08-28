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
        public string $code,
        public string $severity,
        public string $rule,
        public string $reason,
    ) {}

    /**
     * Sorted-by-contract shape of the JSON report entry (file, line, code, rule).
     *
     * @return array{code: string, severity: string, rule: string, file: string, line: int, reason: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'rule' => $this->rule,
            'file' => $this->file,
            'line' => $this->line,
            'reason' => $this->reason,
        ];
    }

    /**
     * Sort key: file, line, code, rule — the report's stable ordering.
     */
    public function sortKey(): string
    {
        return $this->file . "\0" . $this->line . "\0" . $this->code . "\0" . $this->rule;
    }
}
