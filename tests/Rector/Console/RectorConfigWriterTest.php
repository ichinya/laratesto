<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Console;

use Laratesto\Rector\Console\RectorConfigWriter;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

/**
 * PR #8 review point 9: the generated Rector configuration carries base class
 * names in canonical `Tests\ApiTestCase` form — documented forward-slash input
 * is canonicalized, extras deduplicate against the defaults, and malformed or
 * empty values are rejected instead of reaching the rule.
 * Final-review mn4: the temp config path is created exclusively (O_CREAT|O_EXCL) —
 * a pre-existing or symlinked target path is refused, never overwritten or
 * written through.
 */
final class RectorConfigWriterTest
{
    #[Test]
    public function withoutExtraBaseClassesOnlyTheTargetModeIsOverridden(): void
    {
        $writer = new RectorConfigWriter();
        $config = $writer->write('trait', ['tests/Unit'], []);

        try {
            $code = (string) \file_get_contents($config);

            Assert::false(\str_contains($code, 'BASE_CLASSES'), 'No base-class override is needed without extras.');
            Assert::true(\str_contains($code, "'trait'"), 'The target mode override must be written.');
        } finally {
            @\unlink($config);
        }
    }

    #[Test]
    public function extraBaseClassesAreWrittenCanonicalAndDeduplicated(): void
    {
        $writer = new RectorConfigWriter();
        $config = $writer->write('base_class', ['tests'], ['Tests/ApiTestCase', ' Tests\\TestCase ']);

        try {
            $code = (string) \file_get_contents($config);

            // var_export() single-quotes strings, so the canonical backslash form
            // appears as 'Tests\\ApiTestCase' in the generated PHP.
            Assert::true(\str_contains($code, "'Tests\\\\ApiTestCase'"), 'The forward-slash spelling must be canonicalized: ' . $code);
            Assert::same(1, \substr_count($code, "'Tests\\\\TestCase'"), 'A forward-slash extra equal to a default deduplicates against it.');
            Assert::same(1, \substr_count($code, "'Illuminate\\\\Foundation\\\\Testing\\\\TestCase'"));
            Assert::same(0, \substr_count($code, "'Tests/"), 'No forward-slash spelling may survive.');
        } finally {
            @\unlink($config);
        }
    }

    #[Test]
    public function aMalformedExtraBaseClassIsRejected(): void
    {
        try {
            (new RectorConfigWriter())->write('base_class', ['tests'], ['Tests\\\\ApiTestCase']);

            Assert::fail('Expected an InvalidArgumentException for the double-separator input.');
        } catch (\InvalidArgumentException $rejection) {
            Assert::true(\str_contains($rejection->getMessage(), 'Invalid base class'), $rejection->getMessage());
        }
    }

    #[Test]
    public function anEmptyExtraBaseClassIsRejected(): void
    {
        try {
            (new RectorConfigWriter())->write('base_class', ['tests'], ['   ']);

            Assert::fail('Expected an InvalidArgumentException for whitespace-only input.');
        } catch (\InvalidArgumentException $rejection) {
            Assert::true(\str_contains($rejection->getMessage(), 'Invalid base class'), $rejection->getMessage());
        }
    }

    #[Test]
    public function aPreExistingConfigPathIsRefusedInsteadOfOverwritten(): void
    {
        $scratch = $this->makeScratchDir();
        $target = $scratch . '/laratesto-rector-planted.php';
        \file_put_contents($target, 'innocent-payload');

        try {
            try {
                (new RectorConfigWriter(static fn(): string => $target))->write('trait', ['tests/Unit'], []);

                Assert::fail('Expected a RuntimeException for the pre-existing target path.');
            } catch (\RuntimeException $rejection) {
                Assert::true(\str_contains($rejection->getMessage(), 'Refusing to write'), $rejection->getMessage());
                Assert::true(\str_contains($rejection->getMessage(), $target), $rejection->getMessage());
            }

            Assert::same('innocent-payload', (string) \file_get_contents($target), 'A pre-existing target must stay byte-identical.');
        } finally {
            $this->removeScratchDir($scratch);
        }
    }

    #[Test]
    public function aSymlinkedConfigPathIsRefusedInsteadOfFollowed(): void
    {
        $scratch = $this->makeScratchDir();
        $victim = $scratch . '/innocent-payload.txt';
        \file_put_contents($victim, 'innocent-payload');
        $target = $scratch . '/laratesto-rector-planted.php';

        // The mn4 attack: the config path is a link to a victim file. Windows
        // cannot create file symlinks unprivileged, but a junction reparse
        // point trips the same exclusive-create refusal.
        $planted = PHP_OS_FAMILY === 'Windows'
            ? $this->createJunction($target, $victim) || $this->createLink($victim, $target)
            : $this->createLink($victim, $target);

        if (! $planted) {
            $this->removeScratchDir($scratch);

            throw new SkipTest('Links to files are not supported on this platform.');
        }

        try {
            try {
                (new RectorConfigWriter(static fn(): string => $target))->write('trait', ['tests/Unit'], []);

                Assert::fail('Expected a RuntimeException for the symlinked target path.');
            } catch (\RuntimeException $rejection) {
                Assert::true(\str_contains($rejection->getMessage(), 'Refusing to write'), $rejection->getMessage());
            }

            Assert::same('innocent-payload', (string) \file_get_contents($victim), 'The write must never follow the planted link.');
        } finally {
            @\unlink($target);
            $this->removeScratchDir($scratch);
        }
    }

    #[Test]
    public function aDanglingConfigPathLinkIsRefused(): void
    {
        $scratch = $this->makeScratchDir();
        $target = $scratch . '/laratesto-rector-planted.php';
        $missing = $scratch . '/missing-link-target';

        if (PHP_OS_FAMILY === 'Windows') {
            \mkdir($missing, 0777, true);

            if (! $this->createJunction($target, $missing) || ! @\rmdir($missing)) {
                $this->removeScratchDir($scratch);

                throw new SkipTest('Junction creation failed.');
            }
        } elseif (! $this->createLink($missing, $target)) {
            $this->removeScratchDir($scratch);

            throw new SkipTest('Symlinks are not supported on this platform.');
        }

        \clearstatcache(true);

        try {
            try {
                (new RectorConfigWriter(static fn(): string => $target))->write('trait', ['tests/Unit'], []);

                Assert::fail('Expected a RuntimeException for the dangling link target path.');
            } catch (\RuntimeException $rejection) {
                Assert::true(\str_contains($rejection->getMessage(), 'Refusing to write'), $rejection->getMessage());
            }
        } finally {
            @\unlink($target);
            $this->removeScratchDir($scratch);
        }
    }

    private function makeScratchDir(): string
    {
        $scratch = \sys_get_temp_dir() . '/laratesto-config-writer-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        \mkdir($scratch, 0777, true);

        return $scratch;
    }

    private function removeScratchDir(string $scratch): void
    {
        foreach (\glob($scratch . '/*') ?: [] as $entry) {
            @\unlink($entry);
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
