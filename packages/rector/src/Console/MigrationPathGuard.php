<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

/**
 * Validates and normalizes the processed paths and the report path of a migration
 * run. Everything here fails closed: a path that cannot be proven safe rejects the
 * run before Rector starts.
 */
class MigrationPathGuard
{
    /**
     * Normalizes the given paths to absolute real paths inside the project root.
     *
     * Every existing input is resolved through realpath, so `..` segments, symlinked
     * directories and similar-prefix siblings cannot smuggle a path outside the
     * project root. Containment and duplicate checks are case-insensitive on Windows,
     * but the RETURNED paths keep the real filesystem casing — Git pathspecs and the
     * Rector paths must match the work tree byte-for-byte.
     *
     * @param list<string> $given Non-empty path strings, absolute or root-relative.
     * @return list<non-empty-string> Absolute real paths, input order preserved.
     * @throws \InvalidArgumentException With a user-facing message on any rejection.
     */
    public function normalizeProcessedPaths(string $root, array $given): array
    {
        if ($given === []) {
            throw new \InvalidArgumentException('No paths to process were given.');
        }

        $resolvedRoot = $this->normalize($this->mustRealPath($root, 'the project root'));

        $resolved = [];
        $seen = [];

        foreach ($given as $path) {
            $absolute = $this->isAbsolute($path) ? $path : $root . '/' . $this->toForwardSlashes(ltrim($this->toForwardSlashes($path), '/'));

            $real = \realpath($absolute);

            if ($real === false) {
                throw new \InvalidArgumentException(\sprintf(
                    'The processed path "%s" does not exist.',
                    $this->display($root, $absolute),
                ));
            }

            $normalized = $this->normalize($real);

            if ($normalized !== $resolvedRoot && ! \str_starts_with($normalized, $resolvedRoot . '/')) {
                throw new \InvalidArgumentException(\sprintf(
                    'Refusing to process a path outside the project root: %s',
                    $this->display($root, $real),
                ));
            }

            if (! isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $resolved[] = $real;
            }
        }

        // A path nested inside another given path is already covered by it.
        return \array_values(\array_filter(
            $resolved,
            function (string $path) use ($resolved): bool {
                $normalized = $this->normalize($path);

                foreach ($resolved as $other) {
                    if ($other !== $path && \str_starts_with($normalized, $this->normalize($other) . '/')) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * Resolves the report file path and proves it cannot escape the project root and
     * cannot live inside the processed paths (the report must never migrate itself).
     *
     * An existing target must survive the atomic rename, so it is inspected up front:
     * a dot/dot-dot basename, a directory or a special file is rejected here instead of
     * failing the write later, and EVERY symlink/reparse point — dangling or not —
     * is rejected outright: fail-closed, the report write must never go through or
     * replace a link, however safe its effective target looks. is_link() misses
     * Windows junctions, so a realpath that diverges from the given path counts as
     * a link too, and a DANGLING junction answers neither file_exists() nor
     * is_link() — lstat() still sees the reparse point, so it joins the existence
     * oracle. The given final component is returned, so replacement stays atomic
     * at the requested path.
     *
     * @param list<non-empty-string> $processedPaths Normalized processed paths.
     * @return non-empty-string Absolute report file path.
     * @throws \InvalidArgumentException With a user-facing message on any rejection.
     */
    public function resolveReportPath(string $root, string $report, array $processedPaths): string
    {
        $normalizedRoot = $this->normalize($this->mustRealPath($root, 'the project root'));

        if (\trim($report) === '') {
            throw new \InvalidArgumentException('The report path must not be empty.');
        }

        $absolute = $this->isAbsolute($report)
            ? $report
            : $root . '/' . $this->toForwardSlashes(ltrim($this->toForwardSlashes($report), '/'));

        $basename = \basename($absolute);

        // basename() of `foo/.`, `foo/..` or a bare level is that level itself: the
        // report must name a file, so rejecting here beats a rename failure later.
        if ($basename === '.' || $basename === '..') {
            throw new \InvalidArgumentException(\sprintf(
                'The report path "%s" must name a file, not a directory level.',
                $this->display($root, $report),
            ));
        }

        // Resolve as much of the (possibly not yet existing) report path as possible.
        $directory = \str_replace('\\', '/', \dirname(\str_replace('\\', '/', $absolute)));
        $resolvedDirectory = \realpath($directory);

        if ($resolvedDirectory === false) {
            throw new \InvalidArgumentException(\sprintf(
                'The report directory "%s" does not exist.',
                $directory,
            ));
        }

        $reportFile = $resolvedDirectory . '/' . $basename;
        $normalized = $this->normalize($reportFile);

        if (! \str_starts_with($normalized, $normalizedRoot . '/')) {
            throw new \InvalidArgumentException(\sprintf(
                'Refusing to write the report outside the project root: %s',
                $this->display($root, $reportFile),
            ));
        }

        // An existing final component is inspected before the report writer replaces
        // it: directories and special files can never be atomically replaced, and
        // any link is rejected outright — fail-closed. is_link() misses Windows
        // junctions (reparse points), so a realpath that diverges from the given
        // path counts as a link too, and a dangling junction answers neither
        // file_exists() nor is_link(): lstat() is what still sees the reparse point.
        if (\file_exists($reportFile) || \is_link($reportFile) || @\lstat($reportFile) !== false) {
            $target = \realpath($reportFile);

            if (
                \is_link($reportFile)
                || (\is_string($target) && $this->normalize($target) !== $normalized)
                || $target === false
            ) {
                // $target === false here means an unresolvable link: the dangling
                // junction that only lstat() noticed.
                throw new \InvalidArgumentException(\sprintf(
                    'The report path "%s" is a symlink or reparse point; the report must be a plain file path.',
                    $this->display($root, $reportFile),
                ));
            }

            if (\is_dir($reportFile)) {
                throw new \InvalidArgumentException(\sprintf(
                    'The report path "%s" is a directory.',
                    $this->display($root, $reportFile),
                ));
            }

            if (! \is_file($reportFile)) {
                throw new \InvalidArgumentException(\sprintf(
                    'The report path "%s" is not a regular file.',
                    $this->display($root, $reportFile),
                ));
            }
        }

        foreach ($processedPaths as $processed) {
            $processedNormalized = $this->normalize($processed);

            if ($normalized === $processedNormalized || \str_starts_with($normalized, $processedNormalized . '/')) {
                throw new \InvalidArgumentException(
                    'The report path must not live inside the processed paths.',
                );
            }
        }

        return $reportFile;
    }

    /**
     * @return non-empty-string
     */
    private function mustRealPath(string $path, string $what): string
    {
        $real = \realpath($path);

        if ($real === false) {
            throw new \InvalidArgumentException(\sprintf('Unable to resolve %s: %s', $what, $path));
        }

        return $real;
    }

    private function normalize(string $path): string
    {
        $normalized = $this->toForwardSlashes($path);

        // Windows file paths are case-insensitive; compare them consistently.
        return \DIRECTORY_SEPARATOR === '\\' ? \strtolower($normalized) : $normalized;
    }

    private function toForwardSlashes(string $path): string
    {
        return \str_replace('\\', '/', $path);
    }

    private function isAbsolute(string $path): bool
    {
        return \str_starts_with($path, '/') || \preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }

    private function display(string $root, string $absolute): string
    {
        $normalizedRoot = \str_replace('\\', '/', $root) . '/';
        $normalized = \str_replace('\\', '/', $absolute);

        return \str_starts_with($normalized, $normalizedRoot)
            ? \substr($normalized, \strlen($normalizedRoot))
            : $normalized;
    }
}
