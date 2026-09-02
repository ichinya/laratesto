<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

/**
 * Git work-tree facts the apply guard relies on. Every Git invocation goes through
 * the injected {@see ProcessRunner}, its exit code and stderr are checked, and a Git
 * failure fails the caller closed — apply never proceeds on unknown state.
 */
class GitWorkTreeInspector
{
    public function __construct(
        private readonly ProcessRunner $runner,
    ) {}

    public function isInsideWorkTree(string $root): bool
    {
        $outcome = $this->runner->run(['git', '-C', $root, 'rev-parse', '--is-inside-work-tree'], $root);

        if ($outcome->exitCode !== 0) {
            return false;
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

        // Porcelain v1 with NUL delimiters: each entry is XY<space><path>\0 (rename
        // entries carry a second NUL-separated origin which never matters here).
        $entries = \explode("\0", $status->stdout);
        $modified = [];

        foreach ($entries as $entry) {
            if (\strlen($entry) < 4) {
                continue;
            }

            $xy = \substr($entry, 0, 2);
            $path = \substr($entry, 3);

            if ($xy === '??') {
                continue;
            }

            $modified[] = \str_replace('\\', '/', $path);
        }

        return \array_values(\array_unique($modified));
    }
}
