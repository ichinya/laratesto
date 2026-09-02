<?php

declare(strict_types=1);

namespace Laratesto\Rector\Residuals;

/**
 * Reconstructs the new-side content of a file from its original content and the
 * unified diff Rector reports in its machine JSON output.
 *
 * A dry-run never writes to disk, so the scanner needs the virtual content the run
 * WOULD have produced: reconstructed content contains freshly added markers and
 * markers already present on disk, and never markers a hunk removes.
 *
 * @internal Pure string transformation, no filesystem access.
 */
final class UnifiedDiffNewFileReconstructor
{
    /**
     * @param non-empty-string $originalContent
     * @param non-empty-string $unifiedDiff
     * @return non-empty-string
     * @throws \RuntimeException When the diff cannot be applied to the content.
     */
    public function reconstruct(string $originalContent, string $unifiedDiff): string
    {
        // Rector reprints every file with LF endings no matter what the source had,
        // so the reconstruction target is the LF-normalized original.
        $originalContent = \str_replace("\r\n", "\n", $originalContent);

        $trailingNewline = true;

        if (\str_ends_with($originalContent, "\n")) {
            $originalContent = \substr($originalContent, 0, -1);
        } else {
            $trailingNewline = false;
        }

        $original = $originalContent === '' ? [] : \explode("\n", $originalContent);
        $new = [];
        $position = 0;
        $sawHunk = false;

        $lines = \explode("\n", \str_replace("\r\n", "\n", $unifiedDiff));
        $count = \count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];

            if ($line === '' || \str_starts_with($line, '--- ') || \str_starts_with($line, '+++ ')) {
                continue;
            }

            if (\preg_match('/^@@ -(\d+)(?:,(\d+))? \+\d+(?:,\d+)? @@/', $line, $hunk) !== 1) {
                continue;
            }

            $sawHunk = true;
            $hunkStart = (int) $hunk[1] - 1;

            // Copy everything before the hunk verbatim.
            if ($hunkStart < $position) {
                throw new \RuntimeException('The diff hunks overlap and cannot be applied.');
            }

            for (; $position < $hunkStart; $position++) {
                $new[] = $original[$position];
            }

            for ($index++; $index < $count; $index++) {
                $body = $lines[$index];

                if ($body === '\\ No newline at end of file') {
                    $trailingNewline = \str_starts_with($lines[$index - 1] ?? '', ' ');

                    continue;
                }

                if (\str_starts_with($body, '@@ ') || \str_starts_with($body, '--- ') || \str_starts_with($body, '+++ ')) {
                    break;
                }

                // An empty body is an empty context line (a common diff quirk).
                $tag = $body === '' ? ' ' : $body[0];
                $content = $body === '' ? '' : \substr($body, 1);

                if ($tag === ' ') {
                    if (! isset($original[$position]) || $original[$position] !== $content) {
                        throw new \RuntimeException('The diff context does not match the original content.');
                    }

                    $new[] = $original[$position++];
                } elseif ($tag === '-') {
                    if (! isset($original[$position]) || $original[$position] !== $content) {
                        throw new \RuntimeException('The diff removal does not match the original content.');
                    }

                    $position++;
                } elseif ($tag === '+') {
                    $new[] = $content;
                } else {
                    throw new \RuntimeException(\sprintf('Unexpected diff line: %s', $body));
                }
            }

            $index--;
        }

        if (! $sawHunk) {
            throw new \RuntimeException('The diff contains no hunks to apply.');
        }

        // Copy the untouched tail.
        for (; $position < \count($original); $position++) {
            $new[] = $original[$position];
        }

        $content = \implode("\n", $new);

        return $trailingNewline ? $content . "\n" : $content;
    }
}
