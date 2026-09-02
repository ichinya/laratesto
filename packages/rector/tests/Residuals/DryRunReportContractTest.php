<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Residuals;

use Laratesto\Rector\Residuals\Residual;
use Laratesto\Rector\Residuals\ResidualsReport;
use Laratesto\Rector\Residuals\UnifiedDiffNewFileReconstructor;
use Testo\Assert;
use Testo\Test;

/**
 * Dry-run report contract (PR #8 fix plan, stage 6): the new-side content is
 * reconstructed from the original plus the machine diff, and the report sorts the
 * line column numerically with deduplication.
 */
final class DryRunReportContractTest
{
    private UnifiedDiffNewFileReconstructor $reconstructor;

    private ResidualsReport $report;

    public function __construct()
    {
        $this->reconstructor = new UnifiedDiffNewFileReconstructor();
        $this->report = new ResidualsReport();
    }

    #[Test]
    public function hunksAreAppliedToTheOriginalContent(): void
    {
        $original = "<?php\n\nfinal class Demo\n{\n}\n";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -2,3 +2,5 @@
             
            -final class Demo
            +/* laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Rule\A, severity=manual): stays */
            +final class Demo
            +use RuntimeException;
             {
            DIFF;

        $reconstructed = $this->reconstructor->reconstruct($original, $diff);

        Assert::same(
            "<?php\n\n/* laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Rule\\A, severity=manual): stays */\nfinal class Demo\nuse RuntimeException;\n{\n}\n",
            $reconstructed,
        );
    }

    #[Test]
    public function laterHunksCarryTheReconstructedLineNumbers(): void
    {
        $original = "<?php\n\nfinal class Demo\n{\n    public function a(): void {}\n}\n";
        $diff = <<<'DIFF'
            --- Original
            +++ New
            @@ -3,4 +3,4 @@
             final class Demo
             {
            -    public function a(): void {}
            +    public function b(): void {}
             }
            DIFF;

        $reconstructed = $this->reconstructor->reconstruct($original, $diff);

        // The changed method line keeps its new-side line number.
        $line = null;
        foreach (\explode("\n", $reconstructed) as $index => $content) {
            if (\str_contains($content, 'function b()')) {
                $line = $index + 1;
            }
        }

        Assert::same(5, $line);
    }

    #[Test]
    public function aMismatchedDiffIsRejected(): void
    {
        try {
            $this->reconstructor->reconstruct("<?php\nA\n", "--- Original\n+++ New\n@@ -1,2 +1,2 @@\n-X\n+A\n");

            Assert::fail('Expected a RuntimeException for a diff that does not apply.');
        } catch (\RuntimeException) {
            Assert::true(true);
        }
    }

    #[Test]
    public function reportSortsLinesNumericallyAndDeduplicates(): void
    {
        $residual = static fn(int $line): Residual => new Residual(
            file: 'tests/Demo.php',
            line: $line,
            code: 'LIFECYCLE_UNSUPPORTED',
            severity: 'manual',
            rule: 'Rule\A',
            reason: 'r',
        );

        $sorted = $this->report->sorted([
            $residual(11),
            $residual(9),
            $residual(100),
            $residual(9),
        ]);

        Assert::same(
            [9, 11, 100],
            \array_map(static fn(Residual $r): int => $r->line, $sorted),
            'Lines must be sorted numerically: 11 must not come before 9.',
        );
        Assert::same(3, \count($sorted), 'Duplicate findings must be deduplicated.');
    }
}
