<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

/**
 * Git work-tree facts the apply guard relies on. Every Git invocation goes through
 * the injected {@see ProcessRunner}, its exit code and stderr are checked, and a Git
 * failure fails the caller closed — apply never proceeds on unknown state. A Git
 * invocation that cannot run at all (e.g. Git is not installed) throws with the
 * stderr diagnostics instead of degrading into a misleading "not a work tree".
 */
class GitWorkTreeInspector
{
    public function __construct(
        private readonly ProcessRunner $runner,
    ) {}

    /**
     * @throws \RuntimeException When the Git call itself fails (non-zero exit; a
     *         missing Git binary arrives as exit 1 with the start failure in stderr).
     */
    public function isInsideWorkTree(string $root): bool
    {
        $outcome = $this->runner->run(['git', '-C', $root, 'rev-parse', '--is-inside-work-tree'], $root);

        if ($outcome->exitCode !== 0) {
            throw new \RuntimeException(\sprintf(
                'git rev-parse failed (exit %d): %s',
                $outcome->exitCode,
                \trim($outcome->stderr) !== '' ? \trim($outcome->stderr) : 'no stderr output',
            ));
        }

        return \trim($outcome->stdout) === 'true';
    }

    /**
     * The processed paths that Git reports as modified (tracked changes, staged or
     * not). Untracked files are fresh migration input, not a rollback hazard, so
     * they are not reported; anything Git itself cannot answer throws.
     *
     * @param list<non-empty-string> $pathspecs Project-relative or absolute paths.
     * @return list<non-empty-string> Project-relative modified paths.
     * @throws \RuntimeException When the Git status call itself fails.
     */
    public function modifiedPaths(string $root, array $pathspecs): array
    {
        $status = $this->runner->run(
            ['git', '-C', $root, 'status', '--porcelain=v1', '-z', '--', ...$pathspecs],
            $root,
        );

        if ($status->exitCode !== 0) {
            throw new \RuntimeException(\sprintf(
                'git status failed (exit %d): %s',
                $status->exitCode,
                \trim($status->stderr) !== '' ? \trim($status->stderr) : 'no stderr output',
            ));
        }

        // Porcelain v1 with NUL delimiters: each entry is XY<space><path>\0, and a
        // rename/copy entry carries its origin path as a second NUL-separated field
        // immediately after it — that field is consumed as the origin, never parsed
        // as an entry of its own.
        $entries = \explode("\0", $status->stdout);
        $modified = [];

        for ($index = 0, $count = \count($entries); $index < $count; $index++) {
            $entry = $entries[$index];

            if (\strlen($entry) < 4) {
                continue;
            }

            $xy = \substr($entry, 0, 2);
            $path = \substr($entry, 3);

            if ($xy === '??') {
                continue;
            }

            if ($xy[0] === 'R' || $xy[0] === 'C' || $xy[1] === 'R' || $xy[1] === 'C') {
                $index++; // The origin path follows R/C entries as its own field.
            }

            $modified[] = \str_replace('\\', '/', $path);
        }

        return \array_values(\array_unique($modified));
    }
}
