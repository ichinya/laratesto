<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Residuals;

use Laratesto\Rector\Residuals\Residual;
use Laratesto\Rector\Residuals\ResidualsReport;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

/**
 * MiMo finding 2: the report write previously used a predictable PID temp
 * filename with file_put_contents(), so a pre-planted symlink/reparse target
 * was followed. Like RectorConfigWriter, the temp path is now created
 * exclusively (O_CREAT|O_EXCL) behind an lstat() gate — every pre-existing
 * entry is refused, the temp file is cleaned up on every failure, and a
 * successful run fully replaces the report without leaving temp files behind.
 */
final class ResidualsReportWriteHardeningTest
{
    #[Test]
    public function aPreExistingTempPathIsRefusedInsteadOfOverwritten(): void
    {
        $scratch = $this->makeScratchDir();
        $file = $scratch . '/laratesto-residuals.json';
        $temp = $file . '.tmp-' . \getmypid();
        \file_put_contents($temp, 'innocent-payload');

        try {
            try {
                (new ResidualsReport())->write($file, 'dry-run', ['tests/Demo.php'], [$this->validResidual()]);

                Assert::fail('Expected a RuntimeException for the pre-existing temp path.');
            } catch (\RuntimeException $rejection) {
                Assert::true(\str_contains($rejection->getMessage(), 'Refusing to write'), $rejection->getMessage());
                Assert::true(\str_contains($rejection->getMessage(), $temp), $rejection->getMessage());
            }

            Assert::same('innocent-payload', (string) \file_get_contents($temp), 'A pre-existing temp entry must stay byte-identical.');
            Assert::false(\file_exists($file), 'The report itself must not be created by a refused write.');
        } finally {
            $this->removeScratchDir($scratch);
        }
    }

    #[Test]
    public function aPlantedTempLinkIsRefusedInsteadOfFollowed(): void
    {
        $scratch = $this->makeScratchDir();
        $victim = $scratch . '/innocent-payload.txt';
        \file_put_contents($victim, 'innocent-payload');
        $file = $scratch . '/laratesto-residuals.json';
        $temp = $file . '.tmp-' . \getmypid();

        // The attack: the predictable temp path is a link to a victim file.
        // Windows cannot create file symlinks unprivileged, but a junction
        // reparse point trips the same exclusive-create refusal.
        $planted = PHP_OS_FAMILY === 'Windows'
            ? $this->createJunction($temp, $victim) || $this->createLink($victim, $temp)
            : $this->createLink($victim, $temp);

        if (! $planted) {
            $this->removeScratchDir($scratch);

            throw new SkipTest('Links to files are not supported on this platform.');
        }

        try {
            try {
                (new ResidualsReport())->write($file, 'dry-run', ['tests/Demo.php'], [$this->validResidual()]);

                Assert::fail('Expected a RuntimeException for the planted temp link.');
            } catch (\RuntimeException $rejection) {
                Assert::true(\str_contains($rejection->getMessage(), 'Refusing to write'), $rejection->getMessage());
            }

            Assert::same('innocent-payload', (string) \file_get_contents($victim), 'The write must never follow the planted link.');
        } finally {
            @\unlink($temp);
            $this->removeScratchDir($scratch);
        }
    }

    #[Test]
    public function aDanglingTempLinkIsRefused(): void
    {
        $scratch = $this->makeScratchDir();
        $file = $scratch . '/laratesto-residuals.json';
        $temp = $file . '.tmp-' . \getmypid();
        $missing = $scratch . '/missing-link-target';

        if (PHP_OS_FAMILY === 'Windows') {
            \mkdir($missing, 0777, true);

            if (! $this->createJunction($temp, $missing) || ! @\rmdir($missing)) {
                $this->removeScratchDir($scratch);

                throw new SkipTest('Junction creation failed.');
            }
        } elseif (! $this->createLink($missing, $temp)) {
            $this->removeScratchDir($scratch);

            throw new SkipTest('Symlinks are not supported on this platform.');
        }

        \clearstatcache(true);

        try {
            try {
                (new ResidualsReport())->write($file, 'dry-run', ['tests/Demo.php'], [$this->validResidual()]);

                Assert::fail('Expected a RuntimeException for the dangling temp link.');
            } catch (\RuntimeException $rejection) {
                Assert::true(\str_contains($rejection->getMessage(), 'Refusing to write'), $rejection->getMessage());
            }
        } finally {
            @\unlink($temp);
            $this->removeScratchDir($scratch);
        }
    }

    #[Test]
    public function aFailedRenameCleansUpTheTempFileAndKeepsTheReportIntact(): void
    {
        $scratch = $this->makeScratchDir();
        // A directory at the report path makes the final rename fail on every
        // platform (a file never replaces a directory), so the failure path
        // after a successful temp write is exercised portably.
        $file = $scratch . '/laratesto-residuals.json';
        \mkdir($file);
        $temp = $file . '.tmp-' . \getmypid();

        try {
            try {
                (new ResidualsReport())->write($file, 'dry-run', ['tests/Demo.php'], [$this->validResidual()]);

                Assert::fail('Expected a RuntimeException when the report cannot be replaced.');
            } catch (\RuntimeException $rejection) {
                Assert::true(\str_contains($rejection->getMessage(), 'Unable to atomically replace'), $rejection->getMessage());
            }

            Assert::false(\file_exists($temp), 'The temp file must be cleaned up when the rename fails.');
            Assert::true(\is_dir($file), 'The report path must stay intact after a failed replacement.');
        } finally {
            $this->removeScratchDir($scratch);
        }
    }

    #[Test]
    public function aSuccessfulWriteReplacesTheReportAndLeavesNoTempBehind(): void
    {
        $scratch = $this->makeScratchDir();
        $file = $scratch . '/laratesto-residuals.json';
        \file_put_contents($file, 'stale-report');
        $report = new ResidualsReport();

        try {
            $first = $report->write($file, 'dry-run', ['tests/Before.php'], [$this->validResidual()]);
            $second = $report->write($file, 'apply', ['tests/Demo.php'], [$this->validResidual()]);

            Assert::same($second, (string) \file_get_contents($file), 'The report must be fully replaced by the last write.');
            Assert::true(\str_contains((string) \file_get_contents($file), '"apply"'), 'The replacement must carry the new payload, not the stale one.');
            Assert::same($report->render('apply', ['tests/Demo.php'], [$this->validResidual()]), $second, 'The persisted bytes stay deterministic render() output.');

            $leftovers = \glob($scratch . '/*.tmp-*') ?: [];
            Assert::same([], $leftovers, 'A successful write must not leak temp files.');
            Assert::true(\strlen($first) > 0);
        } finally {
            $this->removeScratchDir($scratch);
        }
    }

    private function validResidual(): Residual
    {
        return new Residual(
            file: 'tests/Demo.php',
            line: 5,
            code: 'LIFECYCLE_UNSUPPORTED',
            severity: 'manual',
            rule: 'Rule\A',
            reason: 'laratesto-residual: reason',
        );
    }

    private function makeScratchDir(): string
    {
        $scratch = \sys_get_temp_dir() . '/laratesto-report-write-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        \mkdir($scratch, 0777, true);

        return $scratch;
    }

    private function removeScratchDir(string $scratch): void
    {
        foreach (\glob($scratch . '/*') ?: [] as $entry) {
            \is_dir($entry) ? $this->removeScratchDir($entry) : @\unlink($entry);
        }

        @\rmdir($scratch);
    }

    /**
     * @return bool False when the platform forbids symlink creation, so the test can skip.
     */
    private function createLink(string $target, string $link): bool
    {
        \clearstatcache(true);

        return @\symlink($target, $link) && \is_link($link);
    }

    /**
     * A Windows junction — a reparse point created without privileges.
     */
    private function createJunction(string $link, string $target): bool
    {
        \shell_exec(\sprintf('cmd /c mklink /J %s %s', \escapeshellarg($link), \escapeshellarg($target)));

        \clearstatcache(true);

        return \file_exists($link);
    }
}
