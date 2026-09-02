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
     * @param list<non-empty-string> $processedPaths Normalized processed paths.
     * @return non-empty-string Absolute report file path.
     * @throws \InvalidArgumentException With a user-facing message on any rejection.
     */
    public function resolveReportPath(string $root, string $report, array $processedPaths): string
    {
        $normalizedRoot = $this->normalize($this->mustRealPath($root, 'the project root'));

        $absolute = $this->isAbsolute($report)
            ? $report
            : $root . '/' . $this->toForwardSlashes(ltrim($this->toForwardSlashes($report), '/'));

        // Resolve as much of the (possibly not yet existing) report path as possible.
        $directory = \str_replace('\\', '/', \dirname(\str_replace('\\', '/', $absolute)));
        $resolvedDirectory = \realpath($directory);

        if ($resolvedDirectory === false) {
            throw new \InvalidArgumentException(\sprintf(
                'The report directory "%s" does not exist.',
                $directory,
            ));
        }

        $reportFile = $resolvedDirectory . '/' . \basename($absolute);
        $normalized = $this->normalize($reportFile);

        if (! \str_starts_with($normalized, $normalizedRoot . '/')) {
            throw new \InvalidArgumentException(\sprintf(
                'Refusing to write the report outside the project root: %s',
                $this->display($root, $reportFile),
            ));
        }

        foreach ($processedPaths as $processed) {
            if ($normalized === $this->normalize($processed) || \str_starts_with($normalized, $this->normalize($processed) . '/')) {
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
