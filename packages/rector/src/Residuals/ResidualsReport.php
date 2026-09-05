<?php

declare(strict_types=1);

namespace Laratesto\Rector\Residuals;

/**
 * Writes the deterministic `laratesto-residuals.json` (see the compatibility contract).
 *
 * Always fully replaces the report — including an empty result — via temp file plus
 * atomic rename, with a stable sort by file, line, code, rule and no timestamp, so
 * identical runs produce identical bytes.
 *
 * @api
 */
final class ResidualsReport
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<non-empty-string> $paths Processed paths, relative to the project
     *        root, using forward slashes.
     * @param list<Residual> $residuals
     * @return non-empty-string The report body to persist.
     * @throws \JsonException When the payload cannot be encoded — e.g. invalid UTF-8.
     */
    public function render(string $mode, array $paths, array $residuals): string
    {
        $sorted = $this->sorted($residuals);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $mode,
            'paths' => $paths,
            'residuals' => \array_map(static fn(Residual $r): array => $r->toArray(), $sorted),
        ];

        return \json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
            . "\n";
    }

    /**
     * Deduplicated, deterministically ordered findings: file, then line numerically
     * (10 comes after 9, not after 1), then code, then rule.
     *
     * @param list<Residual> $residuals
     * @return list<Residual>
     */
    public function sorted(array $residuals): array
    {
        $unique = [];
        foreach ($residuals as $residual) {
            $unique[$residual->sortKey()] ??= $residual;
        }

        $sorted = \array_values($unique);
        \usort($sorted, static function (Residual $a, Residual $b): int {
            return [$a->file, $a->line, $a->code, $a->rule] <=> [$b->file, $b->line, $b->code, $b->rule];
        });

        return $sorted;
    }

    /**
     * Atomically replace the report file: write to a sibling temp file, then rename.
     *
     * The temp path is created exclusively and never follows a planted entry:
     * any pre-existing path — a leftover file, a symlink or a Windows reparse
     * point at the predictable PID-suffixed location — is refused, never
     * overwritten or written through.
     *
     * @param list<non-empty-string> $paths
     * @param list<Residual> $residuals
     * @return non-empty-string The rendered body (also persisted).
     * @throws \RuntimeException When the payload cannot be encoded as JSON, or the
     *         file cannot be replaced — the existing report, if any, stays intact.
     */
    public function write(string $file, string $mode, array $paths, array $residuals): string
    {
        try {
            $body = $this->render($mode, $paths, $residuals);
        } catch (\JsonException $encoding) {
            throw new \RuntimeException('Unable to encode the residuals report as JSON: ' . $encoding->getMessage(), previous: $encoding);
        }

        $temp = $file . '.tmp-' . \getmypid();

        // Two fail-closed gates, mirroring RectorConfigWriter::write():
        // lstat() refuses every pre-existing entry — including the dangling
        // reparse points Windows' exclusive create happily "creates through".
        // The exclusive create (`x` = O_CREAT|O_EXCL) then closes the race
        // atomically: a link planted between the gates makes the open fail
        // instead of being followed.
        \clearstatcache(true);

        $refusal = \sprintf('Refusing to write the residuals report temp file "%s": the path already exists and may be a planted link.', $temp);

        if (@\lstat($temp) !== false) {
            throw new \RuntimeException($refusal);
        }

        $handle = @\fopen($temp, 'x');

        if ($handle === false) {
            throw new \RuntimeException($refusal);
        }

        $written = @\fwrite($handle, $body);
        $flushed = @\fflush($handle);
        $closed = @\fclose($handle);

        if ($written === false || $written !== \strlen($body) || $flushed === false || $closed === false) {
            @\unlink($temp);

            throw new \RuntimeException(\sprintf('Unable to write the residuals report to "%s".', $temp));
        }

        if (! @\rename($temp, $file)) {
            @\unlink($temp);

            throw new \RuntimeException(\sprintf('Unable to atomically replace the residuals report at "%s".', $file));
        }

        return $body;
    }
}
