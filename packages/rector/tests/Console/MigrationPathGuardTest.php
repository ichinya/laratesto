<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Console;

use Laratesto\Rector\Console\MigrationPathGuard;
use Laratesto\Rector\Console\RectorJsonResultParser;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

/**
 * Path safety of the migration runner (PR #8 fix plan, stage 7): realpath-based
 * containment, `..` and sibling-prefix escapes rejected, missing paths rejected,
 * duplicates and nested paths collapsed, the report never inside processed paths.
 */
final class MigrationPathGuardTest
{
    private MigrationPathGuard $guard;

    private string $root;

    public function __construct()
    {
        $this->guard = new MigrationPathGuard();
        $this->root = $this->makeTree();
    }

    #[Test]
    public function relativeAndDuplicatePathsNormalizeToRealAbsolutePaths(): void
    {
        $paths = $this->guard->normalizeProcessedPaths($this->root, ['tests', 'tests', $this->root . '/tests/Feature']);

        Assert::same([$this->real('tests')], $paths);
    }

    #[Test]
    public function aPathNestedInsideAnotherGivenPathCollapses(): void
    {
        \mkdir($this->root . '/tests/Nested', 0777, true);

        $paths = $this->guard->normalizeProcessedPaths($this->root, ['tests', 'tests/Nested']);

        Assert::same([$this->real('tests')], $paths);
    }

    #[Test]
    public function dotDotEscapesAboveTheRootAreRejected(): void
    {
        $this->assertRejected(['../'], 'outside the project root');
    }

    #[Test]
    public function anAbsoluteOutsidePathIsRejected(): void
    {
        $this->assertRejected([\dirname($this->root)], 'outside the project root');
    }

    #[Test]
    public function aSiblingWithASimilarPrefixIsRejected(): void
    {
        $sibling = \dirname($this->real('tests')) . '-sibling';
        \mkdir($sibling, 0777, true);

        try {
            $this->guard->normalizeProcessedPaths($this->root, [$sibling]);

            Assert::fail('A similar-prefix sibling must be rejected.');
        } catch (\InvalidArgumentException $rejection) {
            Assert::string($rejection->getMessage())->contains('outside the project root');
        } finally {
            @\rmdir($sibling);
        }
    }

    #[Test]
    public function aMissingPathIsRejectedBeforeRectorRuns(): void
    {
        $this->assertRejected(['tests/Missing'], 'does not exist');
    }

    #[Test]
    public function theReportMayLiveInsideTheRoot(): void
    {
        $report = $this->guard->resolveReportPath($this->root, 'residuals.json', [$this->real('tests')]);

        Assert::same($this->real('') . '/residuals.json', $report);
    }

    #[Test]
    public function aReportInsideProcessedPathsIsRejected(): void
    {
        try {
            $this->guard->resolveReportPath($this->root, 'tests/residuals.json', [$this->real('tests')]);

            Assert::fail('A report inside the processed paths must be rejected.');
        } catch (\InvalidArgumentException $rejection) {
            Assert::string($rejection->getMessage())->contains('must not live inside the processed paths');
        }
    }

    #[Test]
    public function aReportOutsideTheRootIsRejected(): void
    {
        try {
            $this->guard->resolveReportPath($this->root, \dirname($this->root) . '/residuals.json', [$this->real('tests')]);

            Assert::fail('A report outside the root must be rejected.');
        } catch (\InvalidArgumentException $rejection) {
            Assert::string($rejection->getMessage())->contains('outside the project root');
        }
    }

    #[Test]
    public function aReportWithAMissingParentDirectoryIsRejected(): void
    {
        try {
            $this->guard->resolveReportPath($this->root, 'missing-dir/residuals.json', [$this->real('tests')]);

            Assert::fail('A report with a missing parent directory must be rejected.');
        } catch (\InvalidArgumentException $rejection) {
            Assert::string($rejection->getMessage())->contains('does not exist');
        }
    }

    #[Test]
    public function anEmptyReportPathIsRejected(): void
    {
        $this->assertReportRejected('', 'must not be empty');
    }

    #[Test]
    public function aReportWithADotBasenameIsRejected(): void
    {
        $this->assertReportRejected('tests/.', 'must name a file, not a directory level');
    }

    #[Test]
    public function aReportWithADotDotBasenameIsRejected(): void
    {
        $this->assertReportRejected('tests/..', 'must name a file, not a directory level');
        $this->assertReportRejected('..', 'must name a file, not a directory level');
    }

    #[Test]
    public function aReportThatIsADirectoryIsRejected(): void
    {
        $this->assertReportRejected('tests', 'is a directory');
    }

    #[Test]
    public function anExistingRegularReportFileMayBeReplaced(): void
    {
        \file_put_contents($this->root . '/residuals.json', 'stale');

        $report = $this->guard->resolveReportPath($this->root, 'residuals.json', [$this->real('tests')]);

        Assert::same($this->real('') . '/residuals.json', $report);
    }

    #[Test]
    public function aSpecialFileReportTargetIsRejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // The NUL device answers in every directory and is never a regular file.
            $this->assertReportRejected('NUL', 'is not a regular file');

            return;
        }

        if (! $this->makeFifo($this->root . '/fifo-report.json')) {
            throw new SkipTest('No FIFO support on this platform.');
        }

        $this->assertReportRejected('fifo-report.json', 'is not a regular file');
    }

    #[Test]
    public function aSymlinkToARegularFileInsideTheRootIsRejected(): void
    {
        \file_put_contents($this->root . '/report-source.txt', 'x');

        if (! $this->createLink($this->real('') . '/report-source.txt', $this->root . '/link-report.json')) {
            throw new SkipTest('Symlinks are not supported on this platform.');
        }

        $this->assertReportRejected('link-report.json', 'is a symlink or reparse point');
    }

    #[Test]
    public function aSymlinkPointingOutsideTheRootIsRejected(): void
    {
        $outside = \dirname($this->real('')) . '/laratesto-guard-outside-' . \getmypid() . '.txt';
        \file_put_contents($outside, 'x');

        try {
            if (! $this->createLink($outside, $this->root . '/outside-link.json')) {
                throw new SkipTest('Symlinks are not supported on this platform.');
            }

            $this->assertReportRejected('outside-link.json', 'is a symlink or reparse point');
        } finally {
            @\unlink($outside);
        }
    }

    #[Test]
    public function aSymlinkIntoProcessedPathsIsRejected(): void
    {
        \file_put_contents($this->root . '/tests/victim.php', '<?php');

        if (! $this->createLink($this->real('tests/victim.php'), $this->root . '/processed-link.json')) {
            throw new SkipTest('Symlinks are not supported on this platform.');
        }

        $this->assertReportRejected('processed-link.json', 'is a symlink or reparse point');
    }

    #[Test]
    public function aSymlinkToADirectoryIsRejected(): void
    {
        if (! $this->createLink($this->real('tests'), $this->root . '/dir-link.json')) {
            throw new SkipTest('Symlinks are not supported on this platform.');
        }

        $this->assertReportRejected('dir-link.json', 'is a symlink or reparse point');
    }

    #[Test]
    public function aDanglingSymlinkIsRejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows cannot create a symlink with a missing target.
            throw new SkipTest('Windows cannot create dangling symlinks.');
        }

        if (! $this->createLink($this->root . '/gone.json', $this->root . '/dangling-link.json')) {
            throw new SkipTest('Symlinks are not supported on this platform.');
        }

        $this->assertReportRejected('dangling-link.json', 'is a symlink or reparse point');
    }

    #[Test]
    public function aDanglingJunctionIsRejected(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new SkipTest('Junctions are a Windows reparse point.');
        }

        \mkdir($this->root . '/junction-target-dir', 0777, true);

        if (! $this->createJunction($this->root . '/dangling-junction.json', $this->root . '/junction-target-dir')) {
            @\rmdir($this->root . '/junction-target-dir');

            throw new SkipTest('Junction creation failed.');
        }

        // Leave the reparse point pointing at a deleted directory. Which builtins
        // see a dangling junction is build-specific: is_link() answers false on
        // every observed PHP/Windows build, while file_exists() and lstat()
        // disagree across them (PHP 8.4 / Windows 11 vs the CI runner). Assert
        // only what the guard's detection depends on: its union oracle must see
        // the junction, and it must never present as a plain regular file.
        \rmdir($this->root . '/junction-target-dir');
        \clearstatcache(true);
        $junction = $this->root . '/dangling-junction.json';
        Assert::same(true, \file_exists($junction) || \is_link($junction) || @\lstat($junction) !== false);
        Assert::same(false, \is_file($junction));

        $this->assertReportRejected('dangling-junction.json', 'is a symlink or reparse point');
    }

    #[Test]
    public function aJunctionToARegularFileInsideTheRootIsRejected(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new SkipTest('Junctions are a Windows reparse point.');
        }

        \file_put_contents($this->root . '/report-source.txt', 'x');

        if (! $this->createJunction($this->root . '/junction-report.json', $this->real('') . '/report-source.txt')) {
            throw new SkipTest('Junction creation failed.');
        }

        $this->assertReportRejected('junction-report.json', 'is a symlink or reparse point');
    }

    #[Test]
    public function aJunctionPointingOutsideTheRootIsRejected(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new SkipTest('Junctions are a Windows reparse point.');
        }

        $outside = \dirname($this->real('')) . '/laratesto-guard-outside-' . \getmypid() . '.txt';
        \file_put_contents($outside, 'x');

        try {
            if (! $this->createJunction($this->root . '/outside-junction.json', $outside)) {
                throw new SkipTest('Junction creation failed.');
            }

            $this->assertReportRejected('outside-junction.json', 'is a symlink or reparse point');
        } finally {
            @\unlink($outside);
        }
    }

    #[Test]
    public function aJunctionIntoProcessedPathsIsRejected(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new SkipTest('Junctions are a Windows reparse point.');
        }

        \file_put_contents($this->root . '/tests/victim.php', '<?php');

        if (! $this->createJunction($this->root . '/processed-junction.json', $this->real('tests/victim.php'))) {
            throw new SkipTest('Junction creation failed.');
        }

        $this->assertReportRejected('processed-junction.json', 'is a symlink or reparse point');
    }

    #[Test]
    public function aJunctionToADirectoryIsRejected(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new SkipTest('Junctions are a Windows reparse point.');
        }

        if (! $this->createJunction($this->root . '/dir-junction.json', $this->real('tests'))) {
            throw new SkipTest('Junction creation failed.');
        }

        $this->assertReportRejected('dir-junction.json', 'is a symlink or reparse point');
    }

    private function assertRejected(array $paths, string $expectedMessage): void
    {
        try {
            $this->guard->normalizeProcessedPaths($this->root, $paths);

            Assert::fail('Expected the guard to reject: ' . \json_encode($paths));
        } catch (\InvalidArgumentException $rejection) {
            Assert::string($rejection->getMessage())->contains($expectedMessage);
        }
    }

    private function makeTree(): string
    {
        $root = \sys_get_temp_dir() . '/laratesto-path-guard-' . \getmypid();
        \mkdir($root . '/tests/Feature', 0777, true);

        return $root;
    }

    private function real(string $relative): string
    {
        $real = \realpath($this->root . '/' . $relative);
        \assert(\is_string($real) && $real !== '');

        return $real;
    }

    private function assertReportRejected(string $report, string $expectedMessage): void
    {
        try {
            $this->guard->resolveReportPath($this->root, $report, [$this->real('tests')]);

            Assert::fail('Expected the guard to reject the report path: ' . $report);
        } catch (\InvalidArgumentException $rejection) {
            Assert::string($rejection->getMessage())->contains($expectedMessage);
        }
    }

    /**
     * @return bool False when the platform forbids symlink creation (e.g. an
     *         unprivileged Windows), so the test can skip.
     */
    private function createLink(string $target, string $link): bool
    {
        \clearstatcache(true);

        return @\symlink($target, $link) && \is_link($link);
    }

    /**
     * A Windows junction — a mount-point reparse point is_link() never reports.
     */
    private function createJunction(string $link, string $target): bool
    {
        \shell_exec(\sprintf('cmd /c mklink /J %s %s', \escapeshellarg($link), \escapeshellarg($target)));

        \clearstatcache(true);

        return \file_exists($link);
    }

    private function makeFifo(string $path): bool
    {
        if (\function_exists('posix_mkfifo')) {
            @\posix_mkfifo($path, 0644);
        } else {
            \shell_exec(\sprintf('mkfifo %s', \escapeshellarg($path)));
        }

        \clearstatcache(true);

        return \file_exists($path) && ! \is_file($path);
    }
}
