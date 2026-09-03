<?php

declare(strict_types=1);

namespace Laratesto\Rector\Residuals;

/**
 * The scanner reads whatever the caller hands it — file contents after an apply, or
 * the content representing each file during a dry run: the on-disk text, overlaid
 * where a machine-JSON diff exists by the reconstructed new side. Pure string work:
 * no filesystem access, no I/O, so it is trivially testable and reusable from both
 * entry points.
 *
 * Markers are matched as whole canonical comments ({@see ResidualMarker::MARKER_COMMENT_REGEX});
 * prose that merely mentions the marker substring inside a docblock or line comment is
 * never reported. A marker holding several rule contributions yields one {@see Residual}
 * per contribution.
 */
final class ResidualsScanner
{
    /**
     * Scan one file's contents for residual markers.
     *
     * @return list<Residual>
     */
    public function scan(string $file, string $contents): array
    {
        $residuals = [];

        if (\preg_match_all(ResidualMarker::MARKER_COMMENT_REGEX, $contents, $comments, \PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        foreach ($comments[0] as [$comment, $offset]) {
            $line = self::lineAt($contents, (int) $offset);

            if (\preg_match_all(ResidualMarker::MARKER_REGEX, (string) $comment, $matches) === false) {
                continue;
            }

            foreach ($matches['rule'] as $index => $rule) {
                $residuals[] = new Residual(
                    file: $file,
                    line: $line,
                    code: \trim((string) $matches['code'][$index]),
                    severity: \trim((string) ($matches['severity'][$index] ?? 'manual')),
                    rule: \trim((string) $rule),
                    reason: \trim((string) $matches['reason'][$index]),
                );
            }
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
                '- %s:%d [%s/%s] %s',
                $residual->file,
                $residual->line,
                $residual->code,
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
