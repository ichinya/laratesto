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
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<non-empty-string> $paths Processed paths, relative to the project
     *        root, using forward slashes.
     * @param list<Residual> $residuals
     * @return non-empty-string The report body to persist.
     */
    public function render(string $mode, array $paths, array $residuals): string
    {
        $sorted = $residuals;
        \usort($sorted, static fn(Residual $a, Residual $b): int => $a->sortKey() <=> $b->sortKey());

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $mode,
            'paths' => $paths,
            'residuals' => \array_map(static fn(Residual $r): array => $r->toArray(), $sorted),
        ];

        return \json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
            . "\n";
    }

    /**
     * Atomically replace the report file: write to a sibling temp file, then rename.
     *
     * @param list<non-empty-string> $paths
     * @param list<Residual> $residuals
     * @return non-empty-string The rendered body (also persisted).
     */
    public function write(string $file, string $mode, array $paths, array $residuals): string
    {
        $body = $this->render($mode, $paths, $residuals);

        $temp = $file . '.tmp-' . \getmypid();

        if (\file_put_contents($temp, $body) === false) {
            throw new \RuntimeException(\sprintf('Unable to write the residuals report to "%s".', $temp));
        }

        if (! \rename($temp, $file)) {
            @\unlink($temp);

            throw new \RuntimeException(\sprintf('Unable to atomically replace the residuals report at "%s".', $file));
        }

        return $body;
    }
}
