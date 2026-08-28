<?php

declare(strict_types=1);

namespace Laratesto\Rector\Residuals;

/**
 * Collects residual markers from migration output.
 *
 * The scanner reads whatever the caller hands it — file contents after an apply, or
 * processed contents held in memory during a dry run (see the ticket-04 spec: the disk
 * holds no markers in a dry run). Pure string work: no filesystem access, no I/O, so
 * it is trivially testable and reusable from both entry points.
 *
 * @api
 */
final class ResidualsScanner
{
    private const string MARKER_REGEX =
        '/laratesto-residual\(rule=(?<rule>[^):]+)\):\s*(?<reason>[^*]*)\*/';

    /**
     * Scan one file's contents for residual markers.
     *
     * @return list<Residual>
     */
    public function scan(string $file, string $contents): array
    {
        $residuals = [];

        if (\preg_match_all(self::MARKER_REGEX, $contents, $matches, \PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        foreach ($matches[0] as $index => [$marker, $offset]) {
            $residuals[] = new Residual(
                file: $file,
                line: self::lineAt($contents, (int) $offset),
                rule: \trim($matches['rule'][$index][0]),
                reason: \trim($matches['reason'][$index][0]),
            );
        }

        return $residuals;
    }

    /**
     * Render residuals as a console table.
     *
     * @param list<Residual> $residuals
     */
    public function renderTable(array $residuals): string
    {
        if ($residuals === []) {
            return 'No residuals — everything was migrated automatically.';
        }

        $rows = \array_map(
            static fn(Residual $r): array => [
                self::clip($r->file, 38),
                (string) $r->line,
                self::clip($r->rule, 52),
                self::clip($r->reason, 60),
            ],
            $residuals,
        );

        $widths = [4, 4, 4, 6];

        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = \max($widths[$column], \strlen($cell));
            }
        }

        $line = static fn(array $row): string => \implode(' | ', \array_map(
            static fn(string $cell, int $width): string => $cell . \str_repeat(' ', $width - \strlen($cell)),
            $row,
            $widths,
        ));

        $header = $line(['File', 'Line', 'Rule', 'Reason']);
        $separator = \implode('-+-', \array_map(
            static fn(int $width): string => \str_repeat('-', $width),
            $widths,
        ));

        return \implode("\n", [$header, $separator, ... \array_map($line, $rows)]);
    }

    /**
     * Render residuals as the writable report body (one line per finding).
     *
     * @param list<Residual> $residuals
     */
    public function renderReport(array $residuals): string
    {
        if ($residuals === []) {
            return "laratesto migration residuals\n============================\n\nNo residuals — everything was migrated automatically.\n";
        }

        $lines = ["laratesto migration residuals", "============================", ""];

        foreach ($residuals as $residual) {
            $lines[] = \sprintf(
                '- %s:%d [%s] %s',
                $residual->file,
                $residual->line,
                $residual->rule,
                $residual->reason,
            );
        }

        return \implode("\n", $lines) . "\n";
    }

    private static function lineAt(string $contents, int $offset): int
    {
        return \substr_count($contents, "\n", 0, $offset) + 1;
    }

    private static function clip(string $value, int $width): string
    {
        return \strlen($value) <= $width ? $value : \substr($value, 0, $width - 1) . '…';
    }
}
