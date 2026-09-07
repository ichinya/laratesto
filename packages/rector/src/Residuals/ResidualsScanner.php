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
     * Scan one PHP file's contents for residual markers.
     *
     * Ownership, exactly like {@see ResidualMarker}: only a PHP comment whose
     * ENTIRE text is one canonical marker comment is a residual. The scan walks
     * the tokenized source, so a quoted example inside a string literal or
     * heredoc never reaches the match, and prose that merely embeds marker text
     * inside a line comment or docblock fails the full-text match — while a
     * canonical marker comment among other comments is still reported once per
     * rule contribution. Anything the PHP tokenizer does not treat as code (no
     * open tag, inline HTML) offers no comments to own and is not scanned.
     *
     * @return list<Residual>
     */
    public function scan(string $file, string $contents): array
    {
        $residuals = [];

        foreach (\token_get_all($contents) as $token) {
            if (! \is_array($token) || ($token[0] !== \T_COMMENT && $token[0] !== \T_DOC_COMMENT)) {
                continue;
            }

            $comment = $token[1];

            if (\preg_match(ResidualMarker::MARKER_COMMENT_REGEX, $comment, $owned, \PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            [$ownedText, $ownedOffset] = $owned[0];

            if ($ownedOffset !== 0 || \strlen($ownedText) !== \strlen($comment)) {
                continue;
            }

            if (\preg_match_all(ResidualMarker::MARKER_REGEX, $ownedText, $matches) === false) {
                continue;
            }

            $line = (int) $token[2];

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

    private static function clip(string $value, int $width): string
    {
        return \strlen($value) <= $width ? $value : \substr($value, 0, $width - 1) . '…';
    }
}
