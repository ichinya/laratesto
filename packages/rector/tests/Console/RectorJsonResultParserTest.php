<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Console;

use Laratesto\Rector\Console\RectorJsonResultParser;
use Testo\Assert;
use Testo\Test;

/**
 * The Rector machine-JSON schema gate (PR #8 fix plan, stage 7): empty, malformed or
 * incomplete output is a failure — never a silently empty report.
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
    public function missingFileDiffsMeansAnEmptyDiffList(): void
    {
        // The pinned Rector omits file_diffs entirely on runs with no changes.
        $parsed = $this->parser->parse(\json_encode(['totals' => ['errors' => 0, 'changed_files' => 0]]));

        Assert::same([], $parsed['fileDiffs']);
        Assert::same(0, $parsed['errors']);
    }

    #[Test]
    public function anInvalidFileDiffEntryIsAFailure(): void
    {
        $this->assertRejected(\json_encode(['totals' => ['errors' => 0], 'file_diffs' => [['file' => 42]]]), 'invalid file_diffs entry');
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
