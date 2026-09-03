<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Residuals;

use Laratesto\Rector\Residuals\Residual;
use Laratesto\Rector\Residuals\ResidualsReport;
use Testo\Assert;
use Testo\Test;

/**
 * JSON encoding contract (PR #8 review fix): an unencodable payload — e.g. invalid
 * UTF-8 carried in a residual — must surface as a real error, never coerce
 * json_encode(false) into a newline-only report, and a failed write must leave an
 * already-present report byte-for-byte intact.
 */
final class ResidualsReportJsonEncodingTest
{
    private const INVALID_UTF8_REASON = "residual \xB1 byte";

    #[Test]
    public function renderRejectsInvalidUtf8InsteadOfCoercingANewlineReport(): void
    {
        $report = new ResidualsReport();
        $file = $this->tempDir() . '/laratesto-residuals.json';

        try {
            try {
                $report->render('dry-run', ['tests/Demo.php'], [$this->residualWithInvalidUtf8()]);

                Assert::fail('Expected a JsonException for an unencodable payload.');
            } catch (\JsonException $encoding) {
                Assert::string($encoding->getMessage())->contains('UTF-8');
            }
        } finally {
            self::recursiveRemove(\dirname($file));
        }
    }

    #[Test]
    public function writeSurfacesAContextualRuntimeExceptionOnEncodingFailure(): void
    {
        $report = new ResidualsReport();
        $file = $this->tempDir() . '/laratesto-residuals.json';

        try {
            try {
                $report->write($file, 'dry-run', ['tests/Demo.php'], [$this->residualWithInvalidUtf8()]);

                Assert::fail('Expected a RuntimeException for an unencodable payload.');
            } catch (\RuntimeException $failure) {
                Assert::string($failure->getMessage())->contains('Unable to encode the residuals report as JSON');
                Assert::true(
                    $failure->getPrevious() instanceof \JsonException,
                    'The JsonException must stay reachable as the previous exception.',
                );
            }
        } finally {
            self::recursiveRemove(\dirname($file));
        }
    }

    #[Test]
    public function aFailedWritePreservesTheExistingReportByteForByte(): void
    {
        $report = new ResidualsReport();
        $file = $this->tempDir() . '/laratesto-residuals.json';

        try {
            $before = $report->write($file, 'dry-run', ['tests/Before.php'], [$this->validResidual()]);

            try {
                $report->write($file, 'apply', ['tests/Demo.php'], [$this->residualWithInvalidUtf8()]);

                Assert::fail('Expected a RuntimeException for an unencodable payload.');
            } catch (\RuntimeException) {
                // The original report must survive untouched.
            }

            Assert::same($before, \file_get_contents($file), 'The previous report must be preserved on failure.');
            Assert::same([], \glob(\dirname($file) . '/*.tmp-*'), 'No temp file may leak from the failed write.');
        } finally {
            self::recursiveRemove(\dirname($file));
        }
    }

    #[Test]
    public function aValidPayloadKeepsTheDeterministicFormat(): void
    {
        $body = (new ResidualsReport())->render('dry-run', ['tests/Demo.php'], [$this->validResidual()]);

        Assert::same("\n", \substr($body, -1), 'The report body ends with exactly one trailing newline.');
        // JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE stay in effect.
        Assert::string($body)->contains('"tests/Demo.php"');
        Assert::string($body)->contains('laratesto-residual: reason');
    }

    private function residualWithInvalidUtf8(): Residual
    {
        return new Residual(
            file: 'tests/Demo.php',
            line: 5,
            code: 'LIFECYCLE_UNSUPPORTED',
            severity: 'manual',
            rule: 'Rule\A',
            reason: self::INVALID_UTF8_REASON,
        );
    }

    private function validResidual(): Residual
    {
        return new Residual(
            file: 'tests/Before.php',
            line: 7,
            code: 'LIFECYCLE_UNSUPPORTED',
            severity: 'manual',
            rule: 'Rule\A',
            reason: 'laratesto-residual: reason',
        );
    }

    private function tempDir(): string
    {
        $dir = \sys_get_temp_dir() . '/laratesto-report-json-' . \getmypid();

        Assert::true(\mkdir($dir, 0777, true) || \is_dir($dir));

        return $dir;
    }

    private static function recursiveRemove(string $dir): void
    {
        foreach (\glob($dir . '/*') ?: [] as $entry) {
            \is_dir($entry) ? self::recursiveRemove($entry) : @\unlink($entry);
        }

        @\rmdir($dir);
    }
}
