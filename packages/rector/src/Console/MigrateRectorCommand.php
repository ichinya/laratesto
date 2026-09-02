<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

use Illuminate\Console\Command;
use Laratesto\Rector\Residuals\Residual;
use Laratesto\Rector\Residuals\ResidualsReport;
use Laratesto\Rector\Residuals\ResidualsScanner;
use Laratesto\Rector\Residuals\UnifiedDiffNewFileReconstructor;
use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

/**
 * The primary migration UX (see the core flows): one command wrapping the pinned
 * Rector binary with the public set, a dry-run by default, the residuals table and
 * the deterministic `laratesto-residuals.json`.
 *
 * Exit contract: 0 — run succeeded with no manual residuals; 1 — execution, config
 * or report failure; 2 — manual residuals present. Rector's own dry-run exit 2
 * ("changes found") is a successful execution here, not an error.
 */
#[AsCommand(name: 'laratesto:migrate-rector', description: 'Migrate Laravel PHPUnit tests to Laratesto via Rector (dry-run by default)')]
final class MigrateRectorCommand extends Command
{
    /**
     * Exit code: manual residuals present.
     */
    private const int EXIT_MANUAL_RESIDUALS = 2;

    private const int EXIT_FAILURE = 1;

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

    public function __construct(
        private readonly ResidualsScanner $scanner = new ResidualsScanner(),
        private readonly ResidualsReport $report = new ResidualsReport(),
        private readonly UnifiedDiffNewFileReconstructor $reconstructor = new UnifiedDiffNewFileReconstructor(),
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $root = (string) $this->laravel->basePath();
        $apply = (bool) $this->option('apply');

        $targetMode = trim((string) $this->option('target-mode'));
        if (! in_array($targetMode, [
            LaravelBaseClassRector::TARGET_MODE_BASE_CLASS,
            LaravelBaseClassRector::TARGET_MODE_TRAIT,
        ], true)) {
            $this->error('--target-mode must be "base_class" or "trait".');

            return self::EXIT_FAILURE;
        }

        $paths = $this->paths($root);

        $report = (string) ($this->option('report') ?: 'laratesto-residuals.json');
        $reportFile = $this->isAbsolute($report) ? $report : $root . '/' . $this->normalizeRelative($report);

        $rectorBinary = $this->rectorBinary($root);

        if ($rectorBinary === null) {
            $this->error('The pinned rector binary was not found (looked for vendor/rector/rector/bin/rector upwards from the project root).');

            return self::EXIT_FAILURE;
        }

        if ($apply && ! $this->guardCleanPaths($root, $paths)) {
            return self::EXIT_FAILURE;
        }

        foreach ($paths as $path) {
            if (! \str_starts_with(\str_replace('\\', '/', $path), \str_replace('\\', '/', $root) . '/')) {
                $this->error(\sprintf('Refusing to process a path outside the project root: %s', $path));

                return self::EXIT_FAILURE;
            }
        }

        if (\str_ends_with($reportFile, '.php')) {
            foreach ($paths as $path) {
                if (\str_starts_with($reportFile, \rtrim($path, '/\\') . '/') || $reportFile === $path) {
                    $this->error('The report path must not live inside the processed paths.');

                    return self::EXIT_FAILURE;
                }
            }
        }

        $config = $this->writeConfig($paths);

        try {
            $exitCode = $this->runRector($rectorBinary, $config, $root, $apply);
        } finally {
            @\unlink($config);
        }

        // Rector: 0 = clean, 2 = dry-run with changes found (expected), anything else = failure.
        if ($exitCode !== 0 && $exitCode !== 2) {
            $this->error(\sprintf('Rector failed with exit code %d — see the output above. No report was written.', $exitCode));

            return self::EXIT_FAILURE;
        }

        // Whatever happens below, the machine-JSON scratch file must not outlive the run.
        try {
            if ($this->rectorReportedErrors()) {
                $this->error('Rector reported processing errors — see the output above. No report was written.');

                return self::EXIT_FAILURE;
            }

            try {
                $residuals = $this->collectResiduals($apply, $paths);
            } catch (\RuntimeException $failure) {
                $this->error($failure->getMessage() . ' The previous report, if any, was NOT replaced.');

                return self::EXIT_FAILURE;
            }

            $relativePaths = \array_map(
                fn(string $path): string => $this->normalizeRelative(\substr($path, \strlen($root) + 1)),
                $paths,
            );

            try {
                $this->report->write($reportFile, $apply ? 'apply' : 'dry-run', $relativePaths, $residuals);
            } catch (\RuntimeException $failure) {
                $this->error($failure->getMessage());

                return self::EXIT_FAILURE;
            }
        } finally {
            @\unlink($this->tempJsonPath());
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
     * Residuals of the run: dry-run scans the RECONSTRUCTED new-side content of each
     * machine-JSON diff (the disk holds no markers yet, but markers already on disk
     * must still be reported); apply scans the processed paths as they now exist —
     * including files an earlier run already migrated and left carrying markers.
     *
     * @param list<non-empty-string> $paths
     * @return list<Residual>
     */
    private function collectResiduals(bool $apply, array $paths): array
    {
        $root = (string) $this->laravel->basePath();

        if ($apply) {
            $residuals = [];

            foreach ($this->phpFiles($paths) as $absolute) {
                $relative = \str_replace('\\', '/', \substr($absolute, \strlen($root) + 1));

                $residuals = [
                    ...$residuals,
                    ...$this->scanner->scan($relative, (string) \file_get_contents($absolute)),
                ];
            }

            return $residuals;
        }

        // Dry-run: the last machine-JSON run left its diff in this file by runRector().
        $jsonFile = $this->tempJsonPath();
        $contents = \is_file($jsonFile) ? (string) \file_get_contents($jsonFile) : '';

        if ($contents === '') {
            return [];
        }

        try {
            $payload = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Rector produced an unreadable machine JSON output.');
        }

        if (! \is_array($payload)) {
            throw new \RuntimeException('Rector produced an unreadable machine JSON output.');
        }

        $residuals = [];

        foreach ($payload['file_diffs'] ?? [] as $diff) {
            if (! \is_array($diff) || ! \is_string($diff['file'] ?? null) || ! \is_string($diff['diff'] ?? null)) {
                continue;
            }

            $absolute = $this->isAbsolute($diff['file'])
                ? $diff['file']
                : $root . '/' . $this->normalizeRelative($diff['file']);

            if (! \is_file($absolute)) {
                continue;
            }

            $original = (string) \file_get_contents($absolute);
            $virtual = $diff['diff'] === ''
                ? $original
                : $this->reconstructor->reconstruct($original, $diff['diff']);

            $residuals = [
                ...$residuals,
                ...$this->scanner->scan($this->relativeToRoot($root, $absolute), $virtual),
            ];
        }

        return $residuals;
    }

    private function relativeToRoot(string $root, string $absolute): string
    {
        $normalizedRoot = \str_replace('\\', '/', $root) . '/';
        $normalized = \str_replace('\\', '/', $absolute);

        return \str_starts_with($normalized, $normalizedRoot)
            ? \substr($normalized, \strlen($normalizedRoot))
            : $normalized;
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

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                $file->getExtension() === 'php' and $files[] = (string) $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @param list<non-empty-string> $paths
     */
    private function guardCleanPaths(string $root, array $paths): bool
    {
        if ((bool) $this->option('allow-dirty')) {
            $this->warn('--allow-dirty: no safe automatic rollback is provided for this apply; make sure your own backup exists.');

            return true;
        }

        if (! $this->isInsideGitWorkTree($root)) {
            $this->error('The project is not inside a Git work tree; --apply without --allow-dirty refuses to run.');

            return false;
        }

        $status = new Process(['git', '-C', $root, 'status', '--porcelain', '--', ...$paths]);
        $status->run();

        foreach (\explode("\n", \trim($status->getOutput())) as $line) {
            // Untracked files are fine (fresh tests to migrate); modifications are not.
            if ($line !== '' && ! \str_starts_with($line, '??')) {
                $this->error(\sprintf(
                    "Refusing --apply: processed paths have modifications (run without --apply to review, or pass --allow-dirty to override):\n  %s",
                    \substr($line, 3),
                ));

                return false;
            }
        }

        return true;
    }

    private function isInsideGitWorkTree(string $root): bool
    {
        $probe = new Process(['git', '-C', $root, 'rev-parse', '--is-inside-work-tree']);
        $probe->run();

        return \trim($probe->getOutput()) === 'true';
    }

    private function runRector(string $binary, string $config, string $root, bool $apply): int
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

        $process = new Process($command, $root);
        $process->setTimeout(null);
        $process->run();

        // Machine JSON goes to a file for collectResiduals(); human output stays visible.
        \file_put_contents($this->tempJsonPath(), $process->getOutput());

        if ($process->getErrorOutput() !== '') {
            $this->line($process->getErrorOutput());
        }

        return $process->getExitCode() ?? self::EXIT_FAILURE;
    }

    /**
     * @param list<non-empty-string> $paths
     * @return non-empty-string Path to the generated rector config (temp file).
     */
    private function writeConfig(array $paths): string
    {
        // tempnam() creates an empty placeholder we do not use — drop it right away
        // instead of leaking one per run; the actual config lives next to it.
        $base = \tempnam(\sys_get_temp_dir(), 'laratesto-rector-');
        $config = $base . '.php';
        @\unlink($base);

        $pathsCode = \implode(', ', \array_map(
            static fn(string $path): string => \var_export($path, true),
            $paths,
        ));

        $setCode = \var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);

        $extraBases = \array_values(\array_filter(\array_map(
            static fn(mixed $base): string => \trim((string) $base),
            (array) $this->option('base-class'),
        )));
        $targetMode = \trim((string) $this->option('target-mode'));

        $baseConfiguration = $extraBases === []
            ? ''
            : \sprintf(
                '%s::BASE_CLASSES => [%s],',
                '\\' . LaravelBaseClassRector::class,
                \implode(', ', [
                    \var_export('Tests\TestCase', true),
                    \var_export('Illuminate\Foundation\Testing\TestCase', true),
                    ... \array_map(static fn(string $base): string => \var_export($base, true), $extraBases),
                ]),
            );

        $overrideCode = \sprintf(
                <<<'PHP'
                    // The set already registered the rule; this re-configures the same instance.
                    $rectorConfig->ruleWithConfiguration(%s::class, [
                        %s
                        %s::TARGET_MODE => %s,
                    ]);

                    PHP,
                \var_export(LaravelBaseClassRector::class, true),
                $baseConfiguration,
                '\\' . LaravelBaseClassRector::class,
                \var_export($targetMode, true),
            );

        \file_put_contents($config, <<<PHP
            <?php

            declare(strict_types=1);

            use Laratesto\\Rector\\Rules\\LaravelBaseClassRector;
            use Laratesto\\Rector\\Set\\LaratestoRectorSetList;
            use Rector\\Config\\RectorConfig;

            \$builder = RectorConfig::configure()
                ->withPaths([{$pathsCode}])
                ->withSets([{$setCode}]);

            return static function (RectorConfig \$rectorConfig) use (\$builder): void {
                \$builder(\$rectorConfig);
            {$overrideCode}};

            PHP);

        return $config;
    }

    /**
     * @return list<non-empty-string>
     */
    private function paths(string $root): array
    {
        $given = \array_values(\array_filter(\array_map(
            static fn(mixed $path): string => \trim((string) $path),
            (array) $this->option('path'),
        )));

        if ($given === []) {
            $given = ['tests'];
        }

        return \array_map(
            fn(string $path): string => $this->isAbsolute($path)
                ? $path
                : $root . '/' . $this->normalizeRelative($path),
            $given,
        );
    }

    /**
     * POSIX-absolute or Windows drive-absolute.
     */
    private function isAbsolute(string $path): bool
    {
        return \str_starts_with($path, '/') || \preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }

    /**
     * Windows-safe relative path: forward slashes, no leading separator.
     */
    private function normalizeRelative(string $path): string
    {
        return \ltrim(\str_replace('\\', '/', $path), '/');
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

    /**
     * Whether the last machine-JSON run reported processing errors (see totals.errors
     * of the pinned Rector output contract).
     */
    private function rectorReportedErrors(): bool
    {
        $jsonFile = $this->tempJsonPath();
        $contents = \is_file($jsonFile) ? (string) \file_get_contents($jsonFile) : '';

        if ($contents === '') {
            return true;
        }

        try {
            $payload = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return true;
        }

        if (! \is_array($payload)) {
            return true;
        }

        return (int) ($payload['totals']['errors'] ?? 0) > 0;
    }

    private function tempJsonPath(): string
    {
        return \sys_get_temp_dir() . '/laratesto-rector-output-' . \getmypid() . '.json';
    }
}
