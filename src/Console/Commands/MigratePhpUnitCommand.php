<?php

declare(strict_types=1);

namespace Laratesto\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Laratesto\Migration\PhpUnitToTestoMigrator;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Migrate common PHPUnit test files into the Testo source layout.
 */
final class MigratePhpUnitCommand extends Command
{
    protected $signature = 'laratesto:migrate-phpunit
        {source=tests/Unit : PHPUnit test file or directory, relative to the project root}
        {--target= : Target file or directory; defaults to tests/Testo/<suite>}
        {--dry-run : Analyse and show the migration without writing files}
        {--force : Overwrite a different target and allow source removal when warnings remain}
        {--remove-source : Remove each PHPUnit source only after its Testo target is safely written}';

    protected $description = 'Convert common PHPUnit tests to Testo without silently changing unsupported constructs';

    public function __construct(
        private readonly Filesystem $files,
        private readonly PhpUnitToTestoMigrator $migrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $basePath = \realpath(\base_path());
        if ($basePath === false) {
            $this->components->error('Unable to resolve the Laravel project root.');

            return SymfonyCommand::FAILURE;
        }

        try {
            $source = $this->resolveExistingSource($basePath, (string) $this->argument('source'));
            $explicitTarget = $this->option('target');
            $target = \is_string($explicitTarget) && $explicitTarget !== ''
                ? $this->resolveTarget($basePath, $explicitTarget)
                : null;
        } catch (\InvalidArgumentException $error) {
            $this->components->error($error->getMessage());

            return SymfonyCommand::FAILURE;
        }

        $sourceIsDirectory = $this->files->isDirectory($source);
        $sources = $this->sourceFiles($source, $sourceIsDirectory);

        if ($sources === []) {
            $this->components->error('No PHPUnit-looking PHP test files were found in the requested source.');

            return SymfonyCommand::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $removeSource = (bool) $this->option('remove-source');
        $converted = 0;
        $unchanged = 0;
        $failed = 0;
        $warningCount = 0;

        foreach ($sources as $sourceFile) {
            try {
                $targetFile = $this->targetFor(
                    basePath: $basePath,
                    sourceRoot: $source,
                    sourceFile: $sourceFile,
                    sourceIsDirectory: $sourceIsDirectory,
                    explicitTarget: $target,
                );
            } catch (\InvalidArgumentException $error) {
                $this->components->error($error->getMessage());
                $failed++;
                continue;
            }

            if ($this->samePath($sourceFile, $targetFile)) {
                $this->components->error("Source and target are the same file: {$this->relative($basePath, $sourceFile)}");
                $failed++;
                continue;
            }

            $contents = $this->files->get($sourceFile);
            $result = $this->migrator->migrate($contents);
            $sourceLabel = $this->relative($basePath, $sourceFile);
            $targetLabel = $this->relative($basePath, $targetFile);

            if (!$result->successful()) {
                $this->components->error("Refused {$sourceLabel}:");
                foreach ($result->errors as $error) {
                    $this->line("  - {$error}");
                }
                $failed++;
                continue;
            }

            foreach ($result->warnings as $warning) {
                $this->components->warn("{$sourceLabel}: {$warning}");
                $warningCount++;
            }

            if ($this->files->exists($targetFile)) {
                $targetContents = $this->files->get($targetFile);
                if ($targetContents === $result->code) {
                    $this->line("unchanged: {$sourceLabel} -> {$targetLabel}");
                    $unchanged++;

                    if ($removeSource && !$dryRun) {
                        if ($result->warnings !== [] && !$force) {
                            $this->components->error("Kept {$sourceLabel}: warnings remain; review them or pass --force.");
                            $failed++;
                        } else {
                            $this->files->delete($sourceFile);
                            $this->line("removed source: {$sourceLabel}");
                        }
                    }
                    continue;
                }

                if (!$force) {
                    $this->components->error("Target exists with different content: {$targetLabel}. Pass --force to overwrite it.");
                    $failed++;
                    continue;
                }
            }

            if ($dryRun) {
                $this->line("dry-run: {$sourceLabel} -> {$targetLabel}");
                $converted++;
                continue;
            }

            try {
                $this->assertSafeTargetParent($basePath, $targetFile);
                $this->files->ensureDirectoryExists(\dirname($targetFile));
                $this->files->replace($targetFile, $result->code);
            } catch (\Throwable $error) {
                $this->components->error("Unable to write {$targetLabel}: {$error->getMessage()}");
                $failed++;
                continue;
            }

            $this->line("migrated: {$sourceLabel} -> {$targetLabel}");
            $converted++;

            if (!$removeSource) {
                continue;
            }
            if ($result->warnings !== [] && !$force) {
                $this->components->error("Kept {$sourceLabel}: warnings remain; review them or pass --force.");
                $failed++;
                continue;
            }
            if (!$this->files->exists($targetFile) || $this->files->get($targetFile) !== $result->code) {
                $this->components->error("Kept {$sourceLabel}: target verification failed.");
                $failed++;
                continue;
            }

            $this->files->delete($sourceFile);
            $this->line("removed source: {$sourceLabel}");
        }

        $this->newLine();
        $this->components->info(\sprintf(
            'Migration summary: %d converted, %d unchanged, %d warnings, %d failed.',
            $converted,
            $unchanged,
            $warningCount,
            $failed,
        ));
        $this->line('Review testo.php: PHPUnit XML environment, bootstrap and suite settings are not migrated automatically.');
        $this->line('Generated test* methods require Testo\'s NamingConventionPlugin in their target suite.');

        return $failed === 0 ? SymfonyCommand::SUCCESS : SymfonyCommand::FAILURE;
    }

    private function resolveExistingSource(string $basePath, string $source): string
    {
        $candidate = $this->absoluteCandidate($basePath, $source);
        $resolved = \realpath($candidate);

        if ($resolved === false || (!$this->files->isFile($resolved) && !$this->files->isDirectory($resolved))) {
            throw new \InvalidArgumentException("Source does not exist: {$source}");
        }
        if (!$this->inside($basePath, $resolved)) {
            throw new \InvalidArgumentException('The source must stay inside the Laravel project root.');
        }

        return $resolved;
    }

    private function resolveTarget(string $basePath, string $target): string
    {
        $candidate = $this->absoluteCandidate($basePath, $target);

        if (!$this->inside($basePath, $candidate)) {
            throw new \InvalidArgumentException('The target must stay inside the Laravel project root.');
        }

        return $candidate;
    }

    private function absoluteCandidate(string $basePath, string $path): string
    {
        if (\preg_match('#(?:^|[\\\\/])\.\.(?:[\\\\/]|$)#', $path) === 1) {
            throw new \InvalidArgumentException('Parent-directory traversal is not allowed in migration paths.');
        }

        $path = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path);
        $absolute = \preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) === 1;
        $candidate = $absolute ? $path : $basePath . \DIRECTORY_SEPARATOR . $path;

        while (\str_contains($candidate, \DIRECTORY_SEPARATOR . '.' . \DIRECTORY_SEPARATOR)) {
            $candidate = \str_replace(
                \DIRECTORY_SEPARATOR . '.' . \DIRECTORY_SEPARATOR,
                \DIRECTORY_SEPARATOR,
                $candidate,
            );
        }

        return \rtrim($candidate, \DIRECTORY_SEPARATOR);
    }

    /** @return list<string> */
    private function sourceFiles(string $source, bool $directory): array
    {
        if (!$directory) {
            return [$source];
        }

        $files = [];
        foreach ($this->files->allFiles($source) as $file) {
            if (\strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = $file->getRealPath();
            if ($path === false || \str_contains(
                \str_replace('\\', '/', $path),
                '/Testo/',
            )) {
                continue;
            }
            if ($this->migrator->looksLikePhpUnit($this->files->get($path))) {
                $files[] = $path;
            }
        }

        \sort($files);

        return $files;
    }

    private function targetFor(
        string $basePath,
        string $sourceRoot,
        string $sourceFile,
        bool $sourceIsDirectory,
        ?string $explicitTarget,
    ): string {
        if ($explicitTarget !== null) {
            if (!$sourceIsDirectory && \str_ends_with(\strtolower($explicitTarget), '.php')) {
                return $explicitTarget;
            }

            $relative = $sourceIsDirectory
                ? \ltrim(\substr($sourceFile, \strlen($sourceRoot)), '\\/')
                : \basename($sourceFile);

            return $explicitTarget . \DIRECTORY_SEPARATOR . $relative;
        }

        $relative = \str_replace('\\', '/', $this->relative($basePath, $sourceFile));
        $target = \preg_replace('#^tests/(Unit|Feature)/#', 'tests/Testo/$1/', $relative, 1, $count);

        if ($count !== 1 || $target === null) {
            throw new \InvalidArgumentException(
                "Cannot derive a Testo target for {$relative}; pass --target explicitly.",
            );
        }

        return $basePath . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $target);
    }

    private function assertSafeTargetParent(string $basePath, string $target): void
    {
        $ancestor = \dirname($target);
        while (!$this->files->exists($ancestor)) {
            $parent = \dirname($ancestor);
            if ($parent === $ancestor) {
                throw new \RuntimeException('Unable to resolve a safe target ancestor.');
            }
            $ancestor = $parent;
        }

        $resolved = \realpath($ancestor);
        if ($resolved === false || !$this->inside($basePath, $resolved)) {
            throw new \RuntimeException('The target resolves outside the Laravel project root.');
        }
    }

    private function inside(string $basePath, string $path): bool
    {
        $base = \rtrim($basePath, '\\/') . \DIRECTORY_SEPARATOR;
        $candidate = \rtrim($path, '\\/') . (\is_dir($path) ? \DIRECTORY_SEPARATOR : '');

        if (\PHP_OS_FAMILY === 'Windows') {
            $base = \strtolower($base);
            $candidate = \strtolower($candidate);
        }

        return \str_starts_with($candidate, $base);
    }

    private function samePath(string $left, string $right): bool
    {
        $left = \str_replace('\\', '/', $left);
        $right = \str_replace('\\', '/', $right);

        return \PHP_OS_FAMILY === 'Windows'
            ? \strcasecmp($left, $right) === 0
            : $left === $right;
    }

    private function relative(string $basePath, string $path): string
    {
        return \str_replace(
            '\\',
            '/',
            \ltrim(\substr($path, \strlen(\rtrim($basePath, '\\/'))), '\\/'),
        );
    }
}
