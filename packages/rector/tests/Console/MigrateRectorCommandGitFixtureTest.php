<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Console;

use Illuminate\Foundation\Application;
use Laratesto\Rector\Console\MigrateRectorCommand;
use Laratesto\Rector\Console\ProcessOutcome;
use Laratesto\Rector\Console\ProcessRunner;
use Laratesto\Rector\Console\SymfonyProcessRunner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

/**
 * The apply guard against REAL Git work trees (PR #8 review point 10): every
 * scenario runs inside a throwaway Git repository created for the test — never
 * the shared checkout index — so modified, untracked and ignored processed PHP
 * files are refused on their actual porcelain evidence, and a tracked clean
 * apply stays provably recoverable through the documented `git restore`.
 *
 * Every fixture is removed SYNCHRONOUSLY when its test finishes (the shutdown
 * hook is only a safety net): a long-lived test worker process would otherwise
 * keep the directories alive indefinitely.
 */
final class MigrateRectorCommandGitFixtureTest
{
    private const CORPUS = <<<'PHP'
        <?php

        declare(strict_types=1);

        final class GuardedProbeTest
        {
            public function test_probe(): void {}
        }
        PHP;

    #[Test]
    public function aModifiedProcessedFileBlocksApplyInARealWorkTree(): void
    {
        [$repo, $report] = $this->repo();

        try {
            \file_put_contents($repo . '/tests/GuardedTest.php', self::CORPUS . "\n// local edit\n");
            $original = (string) \file_get_contents($repo . '/tests/GuardedTest.php');

            [$exit, $output] = $this->run($repo, $report, apply: true);

            Assert::same(1, $exit, 'A modified processed file must block --apply on real porcelain evidence.');
            Assert::string($output)->contains('Refusing --apply: processed paths have modifications');
            Assert::same($original, (string) \file_get_contents($repo . '/tests/GuardedTest.php'), 'A refused apply must not rewrite anything.');
        } finally {
            self::removeRepository($repo);
        }
    }

    #[Test]
    public function anUntrackedProcessedPhpFileBlocksApplyInARealWorkTree(): void
    {
        [$repo, $report] = $this->repo();

        try {
            \file_put_contents($repo . '/tests/FreshTest.php', self::CORPUS);

            [$exit, $output] = $this->run($repo, $report, apply: true);

            Assert::same(1, $exit, 'An untracked processed PHP file has no commit to restore from.');
            Assert::string($output)->contains('Refusing --apply: these processed PHP files are untracked');
            Assert::string($output)->contains('FreshTest.php');
        } finally {
            self::removeRepository($repo);
        }
    }

    #[Test]
    public function anIgnoredProcessedPhpFileBlocksApplyAndPreservesTheOriginal(): void
    {
        [$repo, $report] = $this->repo();

        try {
            \file_put_contents($repo . '/tests/IgnoredTest.php', self::CORPUS);
            \file_put_contents($repo . '/.gitignore', "tests/IgnoredTest.php\n");
            $original = self::CORPUS;

            [$exit, $output] = $this->run($repo, $report, apply: true);

            Assert::same(1, $exit, 'An ignored processed PHP file cannot be restored by Git at all.');
            Assert::string($output)->contains('Refusing --apply: these processed PHP files are Git-ignored');
            Assert::string($output)->contains('IgnoredTest.php');
            Assert::same($original, (string) \file_get_contents($repo . '/tests/IgnoredTest.php'), 'The refusal must preserve the original bytes.');

            // The scenario is real: plain `git status` hides the file entirely,
            // only the ignored listing names it — and it is what would be lost.
            \exec('git -C ' . \escapeshellarg($repo) . ' status --porcelain=v1 --untracked-files=all --ignored=matching 2>&1', $out, $code);
            Assert::same(0, $code);
            Assert::true(\str_contains(\implode("\n", $out), 'IgnoredTest.php'), 'The fixture file must genuinely be Git-ignored.');
        } finally {
            self::removeRepository($repo);
        }
    }

    #[Test]
    public function phpFilesInsideAnIgnoredProcessedDirectoryBlockApply(): void
    {
        // The whole ignored DIRECTORY collapses to `!! tests/cache/` under
        // `--ignored=matching`; the guard's `traditional` listing must name the
        // PHP file inside, or an entire ignored cache of sources would rewrite
        // with no possibility of restoration.
        [$repo, $report] = $this->repo();

        try {
            \mkdir($repo . '/tests/cache', 0777, true);
            \file_put_contents($repo . '/tests/cache/HiddenTest.php', self::CORPUS);
            \file_put_contents($repo . '/.gitignore', "tests/cache/\n");
            $original = self::CORPUS;

            [$exit, $output] = $this->run($repo, $report, apply: true);

            Assert::same(1, $exit, 'PHP files inside an ignored directory are as unrestorable as loose ignored files.');
            Assert::string($output)->contains('Refusing --apply: these processed PHP files are Git-ignored');
            Assert::string($output)->contains('HiddenTest.php', 'The collapsed directory entry must not hide the PHP file inside.');
            Assert::same($original, (string) \file_get_contents($repo . '/tests/cache/HiddenTest.php'));
        } finally {
            self::removeRepository($repo);
        }
    }

    #[Test]
    public function aCleanTrackedProcessedFileAppliesAndGitRestoreBringsTheOriginalBack(): void
    {
        [$repo, $report] = $this->repo();

        try {
            $original = (string) \file_get_contents($repo . '/tests/GuardedTest.php');

            [$exit, $output] = $this->run($repo, $report, apply: true);

            Assert::same(0, $exit, 'A tracked clean processed file must apply: ' . $output);
            Assert::string($output)->notContains('Refusing --apply');

            // The documented scoped rollback actually works for this fixture
            // shape — simulate the in-place rewrite the real apply performs,
            // then recover.
            \file_put_contents($repo . '/tests/GuardedTest.php', "<?php\n// rewritten\n");
            \exec(
                'git -C ' . \escapeshellarg($repo) . ' restore --source=HEAD -- tests/GuardedTest.php 2>&1',
                $out,
                $restored,
            );
            Assert::same(0, $restored, 'git restore must work in the fixture: ' . \implode("\n", $out));
            Assert::same($original, (string) \file_get_contents($repo . '/tests/GuardedTest.php'), 'The committed original must come back byte for byte.');
        } finally {
            self::removeRepository($repo);
        }
    }

    #[Test]
    public function allowDirtyOverridesTheRealWorkTreeRefusals(): void
    {
        [$repo, $report] = $this->repo();

        try {
            \file_put_contents($repo . '/tests/FreshTest.php', self::CORPUS);

            [$exit, $output] = $this->run($repo, $report, apply: true, allowDirty: true);

            Assert::same(0, $exit, 'The explicit override must let the run proceed: ' . $output);
            Assert::string($output)->contains('allow-dirty', 'The override must warn about the lost safe rollback.');
            Assert::true(\is_file($report));
        } finally {
            self::removeRepository($repo);
        }
    }

    /**
     * Runs the real command with the throwaway repository as the project root —
     * real Git answers every guard question, only the Rector process is canned.
     *
     * @return array{int, string}
     */
    private function run(string $repo, string $report, bool $apply, bool $allowDirty = false): array
    {
        $runner = new class(new SymfonyProcessRunner(), new ProcessOutcome(
            0,
            \json_encode(['totals' => ['errors' => 0], 'file_diffs' => []]),
            '',
        )) implements ProcessRunner {
            public function __construct(
                private readonly SymfonyProcessRunner $real,
                private readonly ProcessOutcome $rector,
            ) {}

            public function run(array $command, string $workingDirectory): ProcessOutcome
            {
                if (\in_array('process', $command, true)) {
                    return $this->rector;
                }

                return $this->real->run($command, $workingDirectory);
            }
        };

        $command = new MigrateRectorCommand(runner: $runner);
        $command->setLaravel(new Application($repo));

        $options = ['--path' => [$repo . '/tests'], '--report' => $report];

        if ($apply) {
            $options['--apply'] = true;
        }

        if ($allowDirty) {
            $options['--allow-dirty'] = true;
        }

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput($options), $output);

        return [$exit, $output->fetch()];
    }

    /**
     * A throwaway Git repository inside the fixture app's storage subtree —
     * invisible to the shared checkout (its own .git answers first, and the
     * storage tree is ignored by the fixture app). The initial commit carries
     * the probe corpus under `tests/`, so the report path stays inside the root
     * but outside the processed paths. The removal hook is registered before
     * any fallible setup step, so a failed init/commit leaves nothing behind.
     *
     * @return array{non-empty-string, non-empty-string}
     */
    private function repo(): array
    {
        \exec('git --version 2>&1', $out, $code);

        if ($code !== 0) {
            throw new SkipTest('Git is not available on this platform.');
        }

        $appRoot = \dirname(__DIR__, 4) . '/tests/Fixture/laravel';
        $repo = $appRoot . '/storage/gitfix-' . \uniqid();
        $report = $repo . '/report.json';

        \register_shutdown_function(static function () use ($repo): void {
            self::removeRepository($repo);
        });

        Assert::true(\mkdir($repo . '/tests', 0777, true) || \is_dir($repo . '/tests'));

        $this->git($repo, 'init');
        $this->git($repo, 'config user.name laratesto-test');
        $this->git($repo, 'config user.email test@laratesto.invalid');
        // Byte-stable adds and restores regardless of the machine's global
        // autocrlf setting: the rollback proof compares file bytes.
        $this->git($repo, 'config core.autocrlf false');
        \file_put_contents($repo . '/tests/GuardedTest.php', self::CORPUS);
        $this->git($repo, 'add -A');
        $this->git($repo, 'commit -m corpus');

        return [$repo, $report];
    }

    /**
     * Windows keeps Git's object and pack files read-only, so a plain unlink
     * silently fails and leaks the whole fixture tree: make every file writable
     * before removing it.
     */
    private static function removeRepository(string $repo): void
    {
        if (! \is_dir($repo)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($repo, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @\rmdir((string) $file);

                continue;
            }

            @\chmod((string) $file, 0666);
            @\unlink((string) $file);
        }

        @\chmod($repo, 0777);
        @\rmdir($repo);
    }

    private function git(string $cwd, string $arguments): void
    {
        \exec('git -C ' . \escapeshellarg($cwd) . ' ' . $arguments . ' 2>&1', $out, $code);

        Assert::same(0, $code, 'git ' . $arguments . ' failed: ' . \implode("\n", $out));
    }
}
