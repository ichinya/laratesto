<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Residuals;

use Laratesto\Rector\Residuals\UnifiedDiffNewFileReconstructor;
use Testo\Assert;
use Testo\Test;

/**
 * EOF newline contract: the "\ No newline at end of file" sentinel describes
 * the side whose last line it follows. A sentinel after a removed line only
 * records the ORIGINAL EOF and must not strip the newline from a newly added
 * last line.
 */
final class UnifiedDiffNewFileReconstructorTest
{
    private UnifiedDiffNewFileReconstructor $reconstructor;

    public function __construct()
    {
        $this->reconstructor = new UnifiedDiffNewFileReconstructor();
    }

    #[Test]
    public function aSentinelAfterARemovalKeepsTheAddedLastLinesNewline(): void
    {
        // Old side ends without a newline, the new side re-adds the last line.
        $original = "<?php\nfinal class Demo\n{\n}";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -2,3 +2,4 @@
             final class Demo
             {
            -}
            \ No newline at end of file
            +}
            +// laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Rule\A, severity=manual): stays
            DIFF;

        Assert::same(
            "<?php\nfinal class Demo\n{\n}\n// laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Rule\\A, severity=manual): stays\n",
            $this->reconstructor->reconstruct($original, $diff),
        );
    }

    #[Test]
    public function aSentinelAfterARemovalEndsTheKeptTailWithANewline(): void
    {
        // Removing the original no-newline last line leaves the kept tail
        // ending with a newline.
        $original = "<?php\nfinal class Demo\n{\n}";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -2,3 +2,2 @@
             final class Demo
             {
            -}
            \ No newline at end of file
            DIFF;

        Assert::same(
            "<?php\nfinal class Demo\n{\n",
            $this->reconstructor->reconstruct($original, $diff),
        );
    }

    #[Test]
    public function aSentinelAfterAnAdditionRemovesTheNewTrailingNewline(): void
    {
        // Old side keeps its newline, the new side's last line loses it.
        $original = "<?php\nfinal class Demo\n{\n}\n";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -2,3 +2,3 @@
             final class Demo
             {
            -}
            +}
            \ No newline at end of file
            DIFF;

        Assert::same(
            "<?php\nfinal class Demo\n{\n}",
            $this->reconstructor->reconstruct($original, $diff),
        );
    }

    #[Test]
    public function aSentinelAfterAContextLineAppliesToBothSides(): void
    {
        // The shared last line lacks the newline on the old and the new side.
        $original = "<?php\nfinal class Demo\n{\n}";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -1,4 +1,4 @@
            -<?php
            +<?php // migrated
             final class Demo
             {
             }
            \ No newline at end of file
            DIFF;

        Assert::same(
            "<?php // migrated\nfinal class Demo\n{\n}",
            $this->reconstructor->reconstruct($original, $diff),
        );
    }

    #[Test]
    public function bothSidesMissingTheNewlineKeepTheRewrittenLineWithoutOne(): void
    {
        // Two sentinels: after the removal (old side) and after the addition
        // (new side); the rewritten last line stays without a newline.
        $original = "<?php\nfinal class Demo\n{\n}";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -2,3 +2,3 @@
             final class Demo
             {
            -}
            \ No newline at end of file
            +}
            \ No newline at end of file
            DIFF;

        Assert::same(
            "<?php\nfinal class Demo\n{\n}",
            $this->reconstructor->reconstruct($original, $diff),
        );
    }

    #[Test]
    public function anEofAboveTheHunkKeepsTheOriginalMissingNewline(): void
    {
        // The diff never reaches the no-newline EOF, so the untouched tail
        // keeps the original's state.
        $original = "<?php\nfinal class Demo\n{\n}";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -1,2 +1,2 @@
            -<?php
            +<?php // migrated
             final class Demo
            DIFF;

        Assert::same(
            "<?php // migrated\nfinal class Demo\n{\n}",
            $this->reconstructor->reconstruct($original, $diff),
        );
    }

    #[Test]
    public function aCrlfOriginalWithAnOldSideSentinelKeepsTheLfNewline(): void
    {
        $original = "<?php\r\nfinal class Demo\r\n{\r\n}";
        $diff = "--- Original\r\n+++ New\r\n@@ -2,3 +2,4 @@\r\n final class Demo\r\n {\r\n-}\r\n\\ No newline at end of file\r\n+}\r\n+// laratesto-residual(code=X, rule=Rule\\A): stays\r\n";

        Assert::same(
            "<?php\nfinal class Demo\n{\n}\n// laratesto-residual(code=X, rule=Rule\\A): stays\n",
            $this->reconstructor->reconstruct($original, $diff),
        );
    }

    #[Test]
    public function aCrlfOriginalWithANewSideSentinelDropsTheLfNewline(): void
    {
        $original = "<?php\r\nfinal class Demo\r\n{\r\n}\r\n";
        $diff = "--- Original\r\n+++ New\r\n@@ -2,3 +2,3 @@\r\n final class Demo\r\n {\r\n-}\r\n+}\r\n\\ No newline at end of file\r\n";

        Assert::same(
            "<?php\nfinal class Demo\n{\n}",
            $this->reconstructor->reconstruct($original, $diff),
        );
    }

    #[Test]
    public function aHunkStartingPastTheOriginalEndThrowsTheDocumentedFailureWithoutAWarning(): void
    {
        $original = "<?php\nfinal class Demo\n{\n}";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -9,1 +9,1 @@
            -gone
            +new
            DIFF;

        $warnings = [];

        // Any PHP warning (undefined array keys from the copy loop) means the
        // reconstruction strayed past the documented RuntimeException contract.
        \set_error_handler(static function (int $number, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            try {
                $this->reconstructor->reconstruct($original, $diff);

                Assert::fail('A hunk starting past the end of the original must throw.');
            } catch (\RuntimeException $failure) {
                Assert::string($failure->getMessage())->contains('past the end');
            }

            Assert::same($warnings, [], 'The defined RuntimeException must fire before any PHP warning.');
        } finally {
            \restore_error_handler();
        }
    }

    #[Test]
    public function aZeroCountHunkAppendsAfterItsAnchorLine(): void
    {
        // Generated pure additions anchor after the numbered old line: append
        // after 5 lines is `@@ -5,0 +6,1 @@`, never `-6,0`.
        $original = "<?php\nfinal class Demo\n{\n}\nlast\n";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -5,0 +6,1 @@
            +// appended
            DIFF;

        Assert::same(
            "<?php\nfinal class Demo\n{\n}\nlast\n// appended\n",
            $this->reconstructor->reconstruct($original, $diff),
            'The zero-count anchor must insert after the numbered line, not at it.',
        );
    }

    #[Test]
    public function aZeroCountHunkAnchoredPastTheContentEndThrows(): void
    {
        $original = "<?php\nfinal class Demo\n{\n}\nlast\n";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -6,0 +7,1 @@
            +// appended
            DIFF;

        try {
            $this->reconstructor->reconstruct($original, $diff);

            Assert::fail('An addition anchored after the last line must throw.');
        } catch (\RuntimeException $failure) {
            Assert::string($failure->getMessage())->contains('past the end');
        }
    }

    #[Test]
    public function aGeneratedContextAnchoredAppendStillApplies(): void
    {
        // The shape a real generated diff uses for an in-place addition: the
        // trailing context lines keep their old-side counts.
        $original = "<?php\nfinal class Demo\n{\n}\nlast\n";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -2,4 +2,5 @@
             final class Demo
             {
             }
             last
            +// appended
            DIFF;

        Assert::same(
            "<?php\nfinal class Demo\n{\n}\nlast\n// appended\n",
            $this->reconstructor->reconstruct($original, $diff),
        );
    }

    #[Test]
    public function aZeroCountEmptyOldSideHunkAnchorsBeforeEverything(): void
    {
        $original = "<?php";
        $diff = <<<'DIFF'
            @@ -0,0 +1,1 @@
            +// note
            DIFF;

        Assert::same(
            "// note\n<?php",
            $this->reconstructor->reconstruct($original, $diff),
            'The empty old side `@@ -0,0 @@` anchors at the very beginning.',
        );
    }

    #[Test]
    public function aGenuinelyEmptyOriginalKeepsTheNewSideEofNewline(): void
    {
        // A zero-byte original carries no EOF state: the generated new side ends
        // with its newline like any other reconstructed content.
        $diff = <<<'DIFF'
            @@ -0,0 +1,1 @@
            +<?php
            DIFF;

        Assert::same(
            "<?php\n",
            $this->reconstructor->reconstruct('', $diff),
            'The empty original must not strip the new side EOF newline.',
        );
    }

    #[Test]
    public function anEmptyOriginalWithANewSideNoNewlineSentinelDropsTheEofNewline(): void
    {
        $diff = <<<'DIFF'
            @@ -0,0 +1,1 @@
            +<?php
            \ No newline at end of file
            DIFF;

        Assert::same(
            '<?php',
            $this->reconstructor->reconstruct('', $diff),
            'A new-side sentinel after an empty original must suppress the EOF newline.',
        );
    }
}
