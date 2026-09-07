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
     * One `git status --porcelain=v1 -z -uall --ignored=traditional` call
     * classifying the processed paths: `modified` are tracked entries with any
     * staged or work-tree change, `untracked` are files Git has never committed
     * (`??`, enumerated per file via `-uall`), and `ignored` are files excluded
     * by ignore rules (`!!`, enumerated per file via `traditional` — `matching`
     * would collapse a whole ignored DIRECTORY into one suffix-less entry that
     * cannot name the PHP files inside). All three buckets need guarding because
     * the scoped rollback `git restore --source=HEAD` cannot restore what no
     * commit ever contained. Anything Git itself cannot answer throws.
     *
     * @param list<non-empty-string> $pathspecs Project-relative or absolute paths.
     * @return array{modified: list<non-empty-string>, untracked: list<non-empty-string>, ignored: list<non-empty-string>} Project-relative paths.
     * @throws \RuntimeException When the Git status call itself fails.
     */
    public function statusPaths(string $root, array $pathspecs): array
    {
        $status = $this->runner->run(
            ['git', '-C', $root, 'status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignored=traditional', '--', ...$pathspecs],
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
        $untracked = [];
        $ignored = [];

        for ($index = 0, $count = \count($entries); $index < $count; $index++) {
            $entry = $entries[$index];

            if (\strlen($entry) < 4) {
                continue;
            }

            $xy = \substr($entry, 0, 2);
            $path = \substr($entry, 3);

            if ($xy === '??') {
                $untracked[] = \str_replace('\\', '/', $path);

                continue;
            }

            if ($xy === '!!') {
                $ignored[] = \str_replace('\\', '/', $path);

                continue;
            }

            if ($xy[0] === 'R' || $xy[0] === 'C' || $xy[1] === 'R' || $xy[1] === 'C') {
                $index++; // The origin path follows R/C entries as its own field.
            }

            $modified[] = \str_replace('\\', '/', $path);
        }

        return [
            'modified' => \array_values(\array_unique($modified)),
            'untracked' => \array_values(\array_unique($untracked)),
            'ignored' => \array_values(\array_unique($ignored)),
        ];
    }
}
