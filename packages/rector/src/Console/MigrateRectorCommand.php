<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

use Illuminate\Console\Command;
use Laratesto\Rector\Residuals\Residual;
use Laratesto\Rector\Residuals\ResidualsReport;
use Laratesto\Rector\Residuals\ResidualsScanner;
use Laratesto\Rector\Residuals\UnifiedDiffNewFileReconstructor;
use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The primary migration UX (see the core flows): one command wrapping the pinned
 * Rector binary with the public set, a dry-run by default, the residuals table and
 * the deterministic `laratesto-residuals.json`.
 *
 * Exit contract: 0 — run succeeded with no manual residuals; 1 — execution, config
 * or report failure; 2 — manual residuals present. Rector's own dry-run exit 2
 * ("changes found") is a successful execution here, not an error — and apply only
 * ever accepts exit 0.
 */
#[AsCommand(name: 'laratesto:migrate-rector', description: 'Migrate Laravel PHPUnit tests to Laratesto via Rector (dry-run by default)')]
final class MigrateRectorCommand extends Command
{
    /**
     * Exit code: manual residuals present.
     */
    private const EXIT_MANUAL_RESIDUALS = 2;

    private const EXIT_FAILURE = 1;

    /**
     * @var string
     */
    protected $signature = 'laratesto:migrate-rector
        {--path=* : Files or directories to process (default: tests)}
        {--base-class=* : Additional source base classes to convert (the defaults stay active)}
        {--target-mode=base_class : Conversion target: base_class or trait}
        {--apply : Write the changes in place instead of a dry-run}
        {--allow-dirty : Allow --apply over modified (non-untracked) processed paths}
        {--report= : Residuals report path, relative to the project root (default: laratesto-residuals.json)}';

    private readonly GitWorkTreeInspector $gitInspector;

    public function __construct(
        private readonly ProcessRunner $runner = new SymfonyProcessRunner(),
        private readonly ResidualsScanner $scanner = new ResidualsScanner(),
        private readonly ResidualsReport $report = new ResidualsReport(),
        private readonly UnifiedDiffNewFileReconstructor $reconstructor = new UnifiedDiffNewFileReconstructor(),
        private readonly RectorJsonResultParser $jsonParser = new RectorJsonResultParser(),
        private readonly RectorConfigWriter $configWriter = new RectorConfigWriter(),
        private readonly MigrationPathGuard $pathGuard = new MigrationPathGuard(),
    ) {
        parent::__construct();

        $this->gitInspector = new GitWorkTreeInspector($runner);
    }

    public function handle(): int
    {
        $root = (string) $this->laravel->basePath();
        $apply = (bool) $this->option('apply');

        $targetMode = \trim((string) $this->option('target-mode'));
        if (! \in_array($targetMode, [
            LaravelBaseClassRector::TARGET_MODE_BASE_CLASS,
            LaravelBaseClassRector::TARGET_MODE_TRAIT,
        ], true)) {
            $this->error('--target-mode must be "base_class" or "trait".');

            return self::EXIT_FAILURE;
        }

        try {
            $paths = $this->pathGuard->normalizeProcessedPaths($root, $this->givenPaths());
            $reportFile = $this->pathGuard->resolveReportPath($root, $this->reportPath(), $paths);
        } catch (\InvalidArgumentException $rejection) {
            $this->error($rejection->getMessage());

            return self::EXIT_FAILURE;
        }

        $rectorBinary = $this->rectorBinary($root);

        if ($rectorBinary === null) {
            $this->error('The pinned rector binary was not found (looked for vendor/rector/rector/bin/rector upwards from the project root).');

            return self::EXIT_FAILURE;
        }

        $allowDirty = (bool) $this->option('allow-dirty');

        if ($apply && $allowDirty) {
            $this->warn('--allow-dirty: no safe automatic rollback is provided for this apply; make sure your own backup exists.');
        } elseif ($apply) {
            if (! $this->guardCleanProcessedPaths($root, $paths)) {
                return self::EXIT_FAILURE;
            }
        }

        try {
            $config = $this->configWriter->write($targetMode, $paths, $this->extraBaseClasses());

            try {
                // Re-check the work tree immediately before the Rector process: the
                // window between the first check and here must stay clean too.
                if ($apply && ! $allowDirty && ! $this->guardCleanProcessedPaths($root, $paths)) {
                    return self::EXIT_FAILURE;
                }

                $outcome = $this->runner->run($this->rectorCommand($rectorBinary, $config, $root, $apply), $root);

                if ($outcome->stderr !== '') {
                    $this->line($outcome->stderr);
                }

                // Rector: 0 = clean, 2 = dry-run with changes found (expected), anything
                // else = failure. An apply run must never report "changes found".
                $acceptableExitCodes = $apply ? [0] : [0, 2];

                if (! \in_array($outcome->exitCode, $acceptableExitCodes, true)) {
                    $this->error(\sprintf('Rector failed with exit code %d — see the output above. No report was written.', $outcome->exitCode));

                    return self::EXIT_FAILURE;
                }

                try {
                    $parsed = $this->jsonParser->parse($outcome->stdout);
                } catch (\RuntimeException $failure) {
                    $this->error($failure->getMessage() . ' No report was written.');

                    return self::EXIT_FAILURE;
                }

                if ($parsed['errors'] > 0) {
                    $this->error('Rector reported processing errors — see the output above. No report was written.');

                    return self::EXIT_FAILURE;
                }

                $residuals = $this->collectResiduals($apply, $parsed['fileDiffs'], $paths, $root);
            } finally {
                @\unlink($config);
            }

            $relativePaths = \array_map(
                fn(string $path): string => $this->relativeToRoot($root, $path),
                $paths,
            );

            try {
                $this->report->write($reportFile, $apply ? 'apply' : 'dry-run', $relativePaths, $residuals);
            } catch (\RuntimeException $failure) {
                $this->error($failure->getMessage() . ' The previous report, if any, was NOT replaced.');

                return self::EXIT_FAILURE;
            }
        } finally {
            // No scratch files survive the run: the machine JSON is parsed in memory.
        }

        $this->line($this->scanner->renderTable($residuals));
        $this->info(\sprintf(
            '%s — report written to %s (%d manual residuals).',
            $apply ? 'Applied' : 'Dry-run (no source files were modified)',
            \basename($reportFile),
            \count($residuals),
        ));

        return $residuals === [] ? 0 : self::EXIT_MANUAL_RESIDUALS;
    }

    /**
     * Residuals of the run — the same walk for both modes so their reports can
     * never disagree: every processed PHP file on disk is scanned exactly once,
     * and in a dry-run a machine-JSON diff OVERLAYS the reconstructed new-side
     * content for its file (freshly added markers appear, markers a hunk removes
     * disappear, markers the new side keeps are reported once with new-side line
     * numbers). Apply reads the files as Rector wrote them — including files an
     * earlier run already migrated and left carrying markers — so a no-op
     * dry-run over an already-migrated tree reports the same residuals as apply.
     *
     * @param list<array{file: string, diff: string}> $fileDiffs
     * @param list<non-empty-string> $paths
     * @return list<Residual>
     */
    private function collectResiduals(bool $apply, array $fileDiffs, array $paths, string $root): array
    {
        // Forward-slashed absolute path => reconstructed new-side content. Only
        // meaningful for a dry-run: after an apply the disk IS the new side.
        $virtual = [];

        if (! $apply) {
            foreach ($fileDiffs as $diff) {
                $absolute = $this->isAbsolute($diff['file'])
                    ? $diff['file']
                    : $root . '/' . $this->toForwardSlashes(\ltrim($diff['file'], '/'));

                if (! \is_file($absolute)) {
                    continue;
                }

                $original = (string) \file_get_contents($absolute);

                $virtual[$this->toForwardSlashes($absolute)] = $diff['diff'] === ''
                    ? $original
                    : $this->reconstructor->reconstruct($original, $diff['diff']);
            }
        }

        $residuals = [];

        foreach ($this->phpFiles($paths) as $absolute) {
            $contents = $virtual[$this->toForwardSlashes($absolute)]
                ?? (string) \file_get_contents($absolute);

            $residuals = [
                ...$residuals,
                ...$this->scanner->scan($this->relativeToRoot($root, $absolute), $contents),
            ];
        }

        return $residuals;
    }

    /**
     * The apply guard: no Git work tree, a failing Git status, or any modified
     * processed path blocks the run.
     *
     * @param list<non-empty-string> $paths
     */
    private function guardCleanProcessedPaths(string $root, array $paths): bool
    {
        if (! $this->gitInspector->isInsideWorkTree($root)) {
            $this->error('The project is not inside a Git work tree; --apply without --allow-dirty refuses to run.');

            return false;
        }

        try {
            $modified = $this->gitInspector->modifiedPaths($root, $paths);
        } catch (\RuntimeException $failure) {
            $this->error('Unable to verify a clean state with Git: ' . $failure->getMessage());

            return false;
        }

        if ($modified !== []) {
            $this->error(\sprintf(
                "Refusing --apply: processed paths have modifications (run without --apply to review, or pass --allow-dirty to override):\n  %s",
                \implode("\n  ", $modified),
            ));

            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function rectorCommand(string $binary, string $config, string $root, bool $apply): array
    {
        $command = [
            \PHP_BINARY,
            $binary,
            'process',
            '--config',
            $config,
            '--output-format=json',
            '--no-ansi',
            '--no-progress-bar',
        ];

        $apply or $command[] = '--dry-run';

        return $command;
    }

    /**
     * Every PHP file under the processed paths (paths may mix files and directories).
     *
     * @param list<non-empty-string> $paths
     * @return list<non-empty-string>
     */
    private function phpFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (\is_file($path)) {
                $files[] = $path;

                continue;
            }

            if (! \is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                $file->getExtension() === 'php' and $files[] = (string) $file->getPathname();
            }
        }

        \sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * @return list<non-empty-string>
     */
    private function givenPaths(): array
    {
        $given = \array_values(\array_filter(\array_map(
            static fn(mixed $path): string => \trim((string) $path),
            (array) $this->option('path'),
        )));

        if ($given === []) {
            $given = ['tests'];
        }

        return $given;
    }

    private function reportPath(): string
    {
        return \trim((string) ($this->option('report') ?: 'laratesto-residuals.json'));
    }

    /**
     * @return list<non-empty-string>
     */
    private function extraBaseClasses(): array
    {
        return \array_values(\array_filter(\array_map(
            static fn(mixed $base): string => \trim((string) $base),
            (array) $this->option('base-class'),
        )));
    }

    private function relativeToRoot(string $root, string $absolute): string
    {
        $normalizedRoot = $this->toForwardSlashes($root) . '/';
        $normalized = $this->toForwardSlashes($absolute);

        return \str_starts_with($normalized, $normalizedRoot)
            ? \substr($normalized, \strlen($normalizedRoot))
            : $normalized;
    }

    private function toForwardSlashes(string $path): string
    {
        return \str_replace('\\', '/', $path);
    }

    private function isAbsolute(string $path): bool
    {
        return \str_starts_with($path, '/') || \preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }

    /**
     * The pinned Rector binary of THIS project tree: vendor dir at the root, or in a
     * parent (monorepo layout where the Laravel app lives in a subdirectory).
     */
    private function rectorBinary(string $root): ?string
    {
        $dir = $root;

        for ($level = 0; $level < 6; $level++) {
            $candidate = $dir . '/vendor/rector/rector/bin/rector';

            if (\is_file($candidate)) {
                return $candidate;
            }

            $parent = \dirname($dir);

            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return null;
    }
}
