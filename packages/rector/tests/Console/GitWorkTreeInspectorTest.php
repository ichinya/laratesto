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
            $inspector->statusPaths('/app', ['tests']);

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
    public function statusClassifiesModifiedUntrackedAndIgnoredEntriesSeparately(): void
    {
        $inspector = new GitWorkTreeInspector(new FakeProcessRunner(gitStatus: new ProcessOutcome(
            0,
            " M tests/Foo.php\0?? tests/New.php\0A  tests/Staged.php\0!! tests/Gone.php\0\0",
            '',
        )));

        $status = $inspector->statusPaths('/app', ['tests']);

        Assert::same($status['modified'], [
            'tests/Foo.php',
            'tests/Staged.php',
        ]);
        Assert::same($status['untracked'], ['tests/New.php'], 'Untracked files are their own rollback hazard.');
        Assert::same($status['ignored'], ['tests/Gone.php'], 'Ignored PHP under the processed paths is equally unrestorable.');
    }

    #[Test]
    public function theStatusCallEnumeratesUntrackedAndIgnoredFilesIndividually(): void
    {
        $runner = new FakeProcessRunner();

        (new GitWorkTreeInspector($runner))->statusPaths('/app', ['tests']);

        Assert::count($runner->invocations, 1);

        $command = $runner->invocations[0]['command'] ?? [];

        Assert::true(
            \in_array('--untracked-files=all', $command, true),
            'A collapsed `?? dir/` entry cannot name the file: -uall must enumerate untracked files.',
        );
        Assert::true(
            \in_array('--ignored=traditional', $command, true),
            'Ignored PHP under the processed paths must be enumerated per file: `matching` collapses a whole ignored directory into one suffix-less entry.',
        );
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

        $status = $inspector->statusPaths('/app', ['tests']);

        Assert::same($status['modified'], [
            'tests/NewTest.php',
        ]);
        Assert::same($status['untracked'], [], 'The origin path must not resurface as untracked.');
        Assert::same($status['ignored'], []);
    }

    #[Test]
    public function theRenameOriginSkipConsumesExactlyOneField(): void
    {
        // The origin field is exactly one chunk: the entry after it must still be
        // parsed as itself, and the untracked entry must be classified as its own.
        $inspector = new GitWorkTreeInspector(new FakeProcessRunner(gitStatus: new ProcessOutcome(
            0,
            "R  tests/NewTest.php\0tests/OldName.php\0 M tests/Touched.php\0?? tests/Fresh.php\0!! tests/Gone.php\0\0",
            '',
        )));

        $status = $inspector->statusPaths('/app', ['tests']);

        Assert::same($status['modified'], [
            'tests/NewTest.php',
            'tests/Touched.php',
        ]);
        Assert::same($status['untracked'], ['tests/Fresh.php'], 'The origin skip must not swallow a following untracked entry.');
        Assert::same($status['ignored'], ['tests/Gone.php']);
    }

    #[Test]
    public function aStagedCopyOriginIsConsumedLikeARename(): void
    {
        $inspector = new GitWorkTreeInspector(new FakeProcessRunner(gitStatus: new ProcessOutcome(
            0,
            "C  tests/Copy.php\0tests/Original.php\0\0",
            '',
        )));

        $status = $inspector->statusPaths('/app', ['tests']);

        Assert::same($status['modified'], [
            'tests/Copy.php',
        ]);
        Assert::same($status['untracked'], []);
        Assert::same($status['ignored'], []);
    }

    #[Test]
    public function aRenamedAndWorkTreeModifiedEntryStillSkipsItsOrigin(): void
    {
        $inspector = new GitWorkTreeInspector(new FakeProcessRunner(gitStatus: new ProcessOutcome(
            0,
            "RM tests/NewTest.php\0tests/OldName.php\0\0",
            '',
        )));

        $status = $inspector->statusPaths('/app', ['tests']);

        Assert::same($status['modified'], [
            'tests/NewTest.php',
        ]);
        Assert::same($status['untracked'], []);
        Assert::same($status['ignored'], []);
    }
}
