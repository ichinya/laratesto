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
     * @param string $originalContent The on-disk content; MAY be empty — a
     *         zero-byte PHP file is legitimate input, and the diff then builds
     *         the whole new side.
     * @param non-empty-string $unifiedDiff
     * @return non-empty-string
     * @throws \RuntimeException When the diff cannot be applied to the content.
     */
    public function reconstruct(string $originalContent, string $unifiedDiff): string
    {
        // Rector reprints every file with LF endings no matter what the source had,
        // so the reconstruction target is the LF-normalized original.
        $originalContent = \str_replace("\r\n", "\n", $originalContent);

        $originalWasEmpty = $originalContent === '';
        $originalHasTrailingNewline = true;

        if (\str_ends_with($originalContent, "\n")) {
            $originalContent = \substr($originalContent, 0, -1);
        } else {
            $originalHasTrailingNewline = false;
        }

        $original = $originalContent === '' ? [] : \explode("\n", $originalContent);
        $new = [];
        $position = 0;

        // The "\ No newline at end of file" sentinel describes the side whose
        // last line it follows, so old-side and new-side EOF state are tracked
        // independently: after a removed line it only records the ORIGINAL
        // EOF and must not strip the newline from a newly added last line.
        $oldEofMissingNewline = false;
        $newEofMissingNewline = false;
        $sawHunk = false;

        $lines = \explode("\n", \str_replace("\r\n", "\n", $unifiedDiff));
        $count = \count($lines);

        // The final newline of the diff terminates the last line; it is not an extra
        // empty context line.
        if ($count > 0 && $lines[$count - 1] === '') {
            \array_pop($lines);
            $count--;
        }

        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];

            if ($line === '' || \str_starts_with($line, '--- ') || \str_starts_with($line, '+++ ')) {
                continue;
            }

            if (\preg_match('/^@@ -(\d+)(?:,(\d+))? \+\d+(?:,\d+)? @@/', $line, $hunk) !== 1) {
                continue;
            }

            $sawHunk = true;

            // Unified-diff anchors: a nonzero old count anchors AT the numbered
            // line (`@@ -5,2 @@` starts at old line 5), so the 0-based index is
            // start-1; a zero-count old range anchors AFTER the numbered line
            // (`@@ -5,0 +6,1 @@` inserts after old line 5 — a pure addition),
            // so the index is start itself, and `@@ -0,0 +1,N @@` (an empty old
            // side) anchors before everything at index 0. A missing count means
            // one line.
            $oldCount = isset($hunk[2]) && $hunk[2] !== '' ? (int) $hunk[2] : 1;
            $hunkStart = $oldCount === 0 ? (int) $hunk[1] : (int) $hunk[1] - 1;

            // A hunk anchored beyond the last original line has nothing to anchor
            // to: the copy loop below would read undefined offsets (a PHP warning
            // instead of the documented RuntimeException), so refuse it up front.
            // A start exactly at the end is legal — that is an append at EOF.
            if ($hunkStart > \count($original)) {
                throw new \RuntimeException(\sprintf(
                    'The diff hunk starts at original line %d, past the end of the %d-line content.',
                    $hunk[1],
                    \count($original),
                ));
            }

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
                    $previousTag = ($lines[$index - 1] ?? '')[0] ?? '';

                    if ($previousTag === '+') {
                        $newEofMissingNewline = true;
                    } elseif ($previousTag === ' ' || $previousTag === '') {
                        // A context line is shared, so its sentinel applies to both sides.
                        $oldEofMissingNewline = true;
                        $newEofMissingNewline = true;
                    } else {
                        // After a removed line the sentinel describes only the old side.
                        $oldEofMissingNewline = true;
                    }

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

        // A sentinel after a removed line means the old no-newline EOF was
        // consumed, so the reconstructed tail ends with a fresh newline; an
        // untouched EOF keeps the original's trailing newline. A GENUINELY
        // EMPTY original carries no EOF state of its own — the new side ends
        // with a newline unless a new-side sentinel says otherwise.
        $trailingNewline = ! $newEofMissingNewline
            && ($oldEofMissingNewline || $originalHasTrailingNewline || $originalWasEmpty);

        return $trailingNewline ? $content . "\n" : $content;
    }
}
