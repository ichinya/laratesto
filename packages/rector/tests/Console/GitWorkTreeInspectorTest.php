<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Console;

use Laratesto\Rector\Console\GitWorkTreeInspector;
use Laratesto\Rector\Console\ProcessOutcome;
use Laratesto\Tests\Support\FakeProcessRunner;
use Testo\Assert;
use Testo\Test;

/**
 * The apply-guard Git facts (PR #8 review M2): an unavailable Git binary fails the
 * caller closed with the stderr diagnostics instead of a misleading answer, the
 * status call keeps its diagnostics contract, and the normal work-tree answers
 * still come through. Reuses the guard tests' canned {@see FakeProcessRunner}.
 */
final class GitWorkTreeInspectorTest
{
    #[Test]
    public function anUnavailableGitBinaryFailsClosedWithItsDiagnostics(): void
    {
        $inspector = new GitWorkTreeInspector(new FakeProcessRunner(gitProbe: new ProcessOutcome(
            1,
            '',
            'Failed to start git: The command "git rev-parse --is-inside-work-tree" failed.',
        )));

        try {
            $inspector->isInsideWorkTree('/app');

            Assert::fail('A Git call that cannot run must throw.');
        } catch (\RuntimeException $failure) {
            Assert::true(\str_contains($failure->getMessage(), 'git rev-parse failed (exit 1)'), $failure->getMessage());
            Assert::true(\str_contains($failure->getMessage(), 'Failed to start git'), $failure->getMessage());
        }
    }

    #[Test]
    public function aFailingStatusCallThrowsWithItsDiagnostics(): void
    {
        $inspector = new GitWorkTreeInspector(new FakeProcessRunner(
            gitStatus: new ProcessOutcome(128, '', 'fatal: not a git repository'),
        ));

        try {
            $inspector->modifiedPaths('/app', ['tests']);

            Assert::fail('A failing Git status must throw.');
        } catch (\RuntimeException $failure) {
            Assert::true(\str_contains($failure->getMessage(), 'git status failed (exit 128)'), $failure->getMessage());
            Assert::true(\str_contains($failure->getMessage(), 'fatal: not a git repository'), $failure->getMessage());
        }
    }

    #[Test]
    public function theWorkTreeAnswerComesFromGitItself(): void
    {
        $inside = new GitWorkTreeInspector(new FakeProcessRunner());
        $outside = new GitWorkTreeInspector(new FakeProcessRunner(
            gitProbe: new ProcessOutcome(0, "false\n", ''),
        ));

        Assert::true($inside->isInsideWorkTree('/app'));
        Assert::false($outside->isInsideWorkTree('/app'));
    }

    #[Test]
    public function trackedModificationsAreReportedAndUntrackedIgnored(): void
    {
        $inspector = new GitWorkTreeInspector(new FakeProcessRunner(gitStatus: new ProcessOutcome(
            0,
            " M tests/Foo.php\0?? tests/New.php\0A  tests/Staged.php\0\0",
            '',
        )));

        Assert::same($inspector->modifiedPaths('/app', ['tests']), [
            'tests/Foo.php',
            'tests/Staged.php',
        ]);
    }

    #[Test]
    public function aStagedRenameOriginIsConsumedRatherThanReparsed(): void
    {
        // Final-review M1: with -z, `R  New\0Old\0` puts the origin in its own
        // NUL-separated field; parsing it as an entry mangles it (`ts/OldName.php`)
        // into a phantom modified path that the apply guard would cite.
        $inspector = new GitWorkTreeInspector(new FakeProcessRunner(gitStatus: new ProcessOutcome(
            0,
            "R  tests/NewTest.php\0tests/OldName.php\0\0",
            '',
        )));

        Assert::same($inspector->modifiedPaths('/app', ['tests']), [
            'tests/NewTest.php',
        ]);
    }

    #[Test]
    public function theRenameOriginSkipConsumesExactlyOneField(): void
    {
        // The origin field is exactly one chunk: the entry after it must still be
        // parsed as itself, and the untracked entry must stay ignored.
        $inspector = new GitWorkTreeInspector(new FakeProcessRunner(gitStatus: new ProcessOutcome(
            0,
            "R  tests/NewTest.php\0tests/OldName.php\0 M tests/Touched.php\0?? tests/Fresh.php\0\0",
            '',
        )));

        Assert::same($inspector->modifiedPaths('/app', ['tests']), [
            'tests/NewTest.php',
            'tests/Touched.php',
        ]);
    }

    #[Test]
    public function aStagedCopyOriginIsConsumedLikeARename(): void
    {
        $inspector = new GitWorkTreeInspector(new FakeProcessRunner(gitStatus: new ProcessOutcome(
            0,
            "C  tests/Copy.php\0tests/Original.php\0\0",
            '',
        )));

        Assert::same($inspector->modifiedPaths('/app', ['tests']), [
            'tests/Copy.php',
        ]);
    }

    #[Test]
    public function aRenamedAndWorkTreeModifiedEntryStillSkipsItsOrigin(): void
    {
        $inspector = new GitWorkTreeInspector(new FakeProcessRunner(gitStatus: new ProcessOutcome(
            0,
            "RM tests/NewTest.php\0tests/OldName.php\0\0",
            '',
        )));

        Assert::same($inspector->modifiedPaths('/app', ['tests']), [
            'tests/NewTest.php',
        ]);
    }
}
