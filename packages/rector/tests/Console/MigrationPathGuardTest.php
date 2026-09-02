<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Console;

use Laratesto\Rector\Console\MigrationPathGuard;
use Laratesto\Rector\Console\RectorJsonResultParser;
use Testo\Assert;
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
}
