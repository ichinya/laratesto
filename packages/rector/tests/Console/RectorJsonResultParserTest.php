<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Console;

use Laratesto\Rector\Console\RectorJsonResultParser;
use Testo\Assert;
use Testo\Test;

/**
 * The Rector machine-JSON schema gate (PR #8 fix plan, stage 7): empty, malformed or
 * incomplete output is a failure — never a silently empty report. `totals.errors`
 * must be present and a non-negative integer exactly as the pinned Rector schema
 * guarantees (native `count()` result): missing values, numeric strings, booleans,
 * floats and arbitrary text are rejected, never coerced to zero. `file_diffs` is
 * optional (the pinned Rector omits it on runs with no changes) but, when present,
 * must be a list of well-formed entries.
 */
final class RectorJsonResultParserTest
{
    private RectorJsonResultParser $parser;

    public function __construct()
    {
        $this->parser = new RectorJsonResultParser();
    }

    #[Test]
    public function aValidPayloadIsParsed(): void
    {
        $parsed = $this->parser->parse(
            \json_encode(['totals' => ['errors' => 0], 'file_diffs' => [['file' => 'tests/A.php', 'diff' => '@@ -1 +1 @@']]]),
        );

        Assert::same(0, $parsed['errors']);
        Assert::same([['file' => 'tests/A.php', 'diff' => '@@ -1 +1 @@']], $parsed['fileDiffs']);
    }

    #[Test]
    public function nonZeroErrorCountsArePreserved(): void
    {
        $parsed = $this->parser->parse(\json_encode(['totals' => ['errors' => 2, 'changed_files' => 1]]));

        Assert::same(2, $parsed['errors']);
        Assert::same([], $parsed['fileDiffs']);
    }

    #[Test]
    public function emptyStdoutIsAFailure(): void
    {
        $this->assertRejected('', 'no machine JSON output');
    }

    #[Test]
    public function malformedJsonIsAFailure(): void
    {
        $this->assertRejected('{nope', 'malformed machine JSON');
    }

    #[Test]
    public function missingTotalsAreAFailure(): void
    {
        $this->assertRejected(\json_encode(['file_diffs' => []]), 'missing the required totals block');
    }

    #[Test]
    public function nonArrayTotalsAreAFailure(): void
    {
        $this->assertRejected(\json_encode(['totals' => 'zero']), 'missing the required totals block');
    }

    /**
     * Adversarial matrix for `totals.errors`: nothing but a present, native,
     * non-negative integer may pass — no defaulting, no coercion to zero.
     */
    #[Test]
    public function totalsErrorsAreStrictlyANonnegativeInteger(): void
    {
        $payloads = [
            'missing key' => ['totals' => ['changed_files' => 0]],
            'null' => ['totals' => ['errors' => null]],
            'numeric string zero' => ['totals' => ['errors' => '0']],
            'numeric string' => ['totals' => ['errors' => '3']],
            // Integral floats are indistinguishable from ints through json_encode, so
            // these two arrive as raw JSON literals to prove the wire form is rejected.
            'float zero' => '{"totals":{"errors":0.0}}',
            'integral float' => '{"totals":{"errors":2.0}}',
            'float fraction' => ['totals' => ['errors' => 1.5]],
            'true' => ['totals' => ['errors' => true]],
            'false' => ['totals' => ['errors' => false]],
            'negative' => ['totals' => ['errors' => -1]],
            'text' => ['totals' => ['errors' => 'oops']],
            'list' => ['totals' => ['errors' => [0]]],
        ];

        foreach ($payloads as $label => $payload) {
            try {
                $this->parser->parse(\is_string($payload) ? $payload : \json_encode($payload));

                Assert::fail("Expected totals.errors [{$label}] to be rejected.");
            } catch (\RuntimeException $failure) {
                Assert::string($failure->getMessage())->contains('totals.errors must be a non-negative integer');
            }
        }
    }

    #[Test]
    public function missingFileDiffsMeansAnEmptyDiffList(): void
    {
        // The pinned Rector omits file_diffs entirely on runs with no changes.
        $parsed = $this->parser->parse(\json_encode(['totals' => ['errors' => 0, 'changed_files' => 0]]));

        Assert::same([], $parsed['fileDiffs']);
        Assert::same(0, $parsed['errors']);
    }

    #[Test]
    public function anEmptyFileDiffsListIsValid(): void
    {
        $parsed = $this->parser->parse(\json_encode(['totals' => ['errors' => 0], 'file_diffs' => []]));

        Assert::same([], $parsed['fileDiffs']);
    }

    /**
     * Adversarial matrix for the `file_diffs` container: when present it must be a
     * list of arrays — scalars, null, booleans and keyed objects fail closed.
     */
    #[Test]
    public function fileDiffsContainerIsStrictlyAList(): void
    {
        $containers = [
            'string' => 'nope',
            'integer' => 5,
            'null' => null,
            'true' => true,
            'false' => false,
            'keyed object' => ['a.php' => ['file' => 'a.php', 'diff' => '@@ -1 +1 @@']],
        ];

        foreach ($containers as $label => $fileDiffs) {
            try {
                $this->parser->parse(\json_encode(['totals' => ['errors' => 0], 'file_diffs' => $fileDiffs]));

                Assert::fail("Expected file_diffs [{$label}] to be rejected.");
            } catch (\RuntimeException $failure) {
                Assert::string($failure->getMessage())->contains('invalid file_diffs container');
            }
        }
    }

    #[Test]
    public function anInvalidFileDiffEntryIsAFailure(): void
    {
        $this->assertRejected(\json_encode(['totals' => ['errors' => 0], 'file_diffs' => [['file' => 42]]]), 'invalid file_diffs entry');

        $this->assertRejected(
            \json_encode(['totals' => ['errors' => 0], 'file_diffs' => [['file' => 'tests/A.php']]]),
            'invalid file_diffs entry',
        );

        $this->assertRejected(
            \json_encode(['totals' => ['errors' => 0], 'file_diffs' => [['file' => 'tests/A.php', 'diff' => 7]]]),
            'invalid file_diffs entry',
        );

        // A well-formed list with malformed members is an entry-level failure, not a container one.
        $this->assertRejected(
            \json_encode(['totals' => ['errors' => 0], 'file_diffs' => ['nope']]),
            'invalid file_diffs entry',
        );

        $this->assertRejected(
            \json_encode(['totals' => ['errors' => 0], 'file_diffs' => [null]]),
            'invalid file_diffs entry',
        );
    }

    private function assertRejected(string $stdout, string $expectedMessage): void
    {
        try {
            $this->parser->parse($stdout);

            Assert::fail('Expected the parser to reject the payload.');
        } catch (\RuntimeException $failure) {
            Assert::string($failure->getMessage())->contains($expectedMessage);
        }
    }
}
