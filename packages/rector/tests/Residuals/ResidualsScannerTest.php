<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Residuals;

use Laratesto\Rector\Residuals\Residual;
use Laratesto\Rector\Residuals\ResidualsScanner;
use Testo\Assert;
use Testo\Test;

final class ResidualsScannerTest
{
    private ResidualsScanner $scanner;

    public function __construct()
    {
        $this->scanner = new ResidualsScanner();
    }

    #[Test]
    public function findsNothingInCleanCode(): void
    {
        $residuals = $this->scanner->scan('CleanTest.php', <<<'PHP'
            <?php

            final class CleanTest
            {
                public function test_clean(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP,
        );

        Assert::same($residuals, []);
        Assert::true(str_starts_with($this->scanner->renderTable($residuals), 'No residuals'));
    }

    #[Test]
    public function collectsMarkerWithFileLineCodeRuleAndReason(): void
    {
        $residuals = $this->scanner->scan('SignupTest.php', <<<"PHP"
            <?php

            final class First
            {
            }

            /* laratesto-residual(code=LARAVEL_FAKE_UNSUPPORTED, rule=Laratesto\\Rector\\Rules\\LaravelResidualDetectionRector, severity=manual): Mail::fake() — no automatic conversion; migrate manually */
            final class SignupTest
            {
            }
            PHP,
        );

        Assert::count($residuals, 1);

        $residual = $residuals[0];
        \assert($residual instanceof Residual);

        Assert::same($residual->file, 'SignupTest.php');
        Assert::same($residual->line, 7);
        Assert::same($residual->code, 'LARAVEL_FAKE_UNSUPPORTED');
        Assert::same($residual->severity, 'manual');
        Assert::same($residual->rule, 'Laratesto\Rector\Rules\LaravelResidualDetectionRector');
        Assert::same($residual->reason, 'Mail::fake() — no automatic conversion; migrate manually');
    }

    #[Test]
    public function rendersTableAndReport(): void
    {
        $residuals = $this->scanner->scan('A.php', "<?php\n/* laratesto-residual(code=TEST_ONE, rule=RuleOne, severity=manual): first reason */\n");
        $residuals = [...$residuals, ...$this->scanner->scan('B.php', "<?php\nline\n/* laratesto-residual(code=TEST_TWO, rule=RuleTwo, severity=manual): second reason */\n")];

        $table = $this->scanner->renderTable($residuals);

        Assert::true(str_contains($table, 'File'));
        Assert::true(str_contains($table, 'A.php'));
        Assert::true(str_contains($table, 'RuleTwo'));
        Assert::true(str_contains($table, 'second reason'));

        $report = $this->scanner->renderReport($residuals);

        Assert::true(str_contains($report, '- B.php:3 [TEST_TWO/RuleTwo] second reason'));
        Assert::count(explode("\n", $report), 6);
    }

    #[Test]
    public function aMergedMarkerReportsOneResidualPerRule(): void
    {
        $residuals = $this->scanner->scan('CollisionTest.php', <<<'PHP'
            <?php

            /* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Rule\A, severity=manual): base class reason; laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Rule\B, severity=warning): detection reason */
            final class CollisionTest
            {
            }
            PHP);

        Assert::count($residuals, 2, 'One marker comment, one finding per rule contribution.');

        $first = $residuals[0] ?? null;
        $second = $residuals[1] ?? null;
        \assert($first instanceof Residual && $second instanceof Residual);

        Assert::same($first->code, 'HTTP_UNSUPPORTED_SIGNATURE');
        Assert::same($first->rule, 'Rule\A');
        Assert::same($first->severity, 'manual');
        Assert::same($first->reason, 'base class reason', 'The separator must not leak into the parsed reason.');
        Assert::same($second->rule, 'Rule\B');
        Assert::same($second->severity, 'warning');
        Assert::same($second->reason, 'detection reason');
        Assert::same($first->line, $second->line);

        $report = $this->scanner->renderReport($residuals);

        Assert::true(str_contains($report, '[HTTP_UNSUPPORTED_SIGNATURE/Rule\A] base class reason'));
        Assert::true(str_contains($report, '[HTTP_UNSUPPORTED_SIGNATURE/Rule\B] detection reason'));
    }

    #[Test]
    public function ignoresProseThatMerelyMentionsTheMarker(): void
    {
        $residuals = $this->scanner->scan('DocsTest.php', <<<'PHP'
            <?php

            /**
             * To opt out manually, write: laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Rule\A, severity=manual): example reason
             */
            final class DocsTest
            {
            }

            // laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Rule\B, severity=warning): another example
            final class OtherTest
            {
            }
            PHP);

        Assert::same($residuals, [], 'Prose mentioning the marker substring is user documentation, not a residual.');
    }

    #[Test]
    public function aCanonicalMarkerBesideProseIsStillFound(): void
    {
        $residuals = $this->scanner->scan('MixedTest.php', <<<'PHP'
            <?php

            /**
             * How to mark by hand: see the migration guide.
             */
            /* laratesto-residual(code=LARAVEL_FAKE_UNSUPPORTED, rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual): Mail::fake() — migrate manually */
            final class MixedTest
            {
            }
            PHP);

        Assert::count($residuals, 1, 'Only the standalone canonical comment is a finding.');

        $residual = $residuals[0] ?? null;
        \assert($residual instanceof Residual);

        Assert::same($residual->line, 6);
        Assert::same($residual->code, 'LARAVEL_FAKE_UNSUPPORTED');
        Assert::same($residual->severity, 'manual');
        Assert::same($residual->rule, 'Laratesto\Rector\Rules\LaravelResidualDetectionRector');
        Assert::same($residual->reason, 'Mail::fake() — migrate manually');
    }

    #[Test]
    public function aQuotedMarkerExampleInsideAStringIsNotAResidual(): void
    {
        $residuals = $this->scanner->scan('ReadmeExampleTest.php', <<<'PHP'
            <?php

            final class ReadmeExampleTest
            {
                public function test_documentation_example(): void
                {
                    $example = '/* laratesto-residual(code=LARAVEL_FAKE_UNSUPPORTED, rule=LaravelResidualDetectionRector, severity=manual): Mail::fake() — migrate manually */';

                    self::assertTrue($example !== '');
                }
            }
            PHP);

        Assert::same($residuals, [], 'A quoted example of the marker is user documentation, not a residual.');
    }

    #[Test]
    public function aMarkerEmbeddedInsideALineCommentIsNotAResidual(): void
    {
        $residuals = $this->scanner->scan('ProseTest.php', <<<'PHP'
            <?php

            // To opt out manually, write: /* laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Rule\A, severity=manual): example reason; */
            final class ProseTest
            {
            }
            PHP);

        Assert::same($residuals, [], 'The canonical wrapper inside a line comment is prose, not an owned marker.');
    }

    #[Test]
    public function aMarkerInsideAHeredocIsNotAResidual(): void
    {
        $residuals = $this->scanner->scan('HeredocTest.php', <<<'PHP'
            <?php

            final class HeredocTest
            {
                public function test_embedded_docs(): void
                {
                    $guide = <<<GUIDE
                    /* laratesto-residual(code=LARAVEL_FAKE_UNSUPPORTED, rule=LaravelResidualDetectionRector, severity=manual): Mail::fake() — migrate manually */
                    GUIDE;
                }
            }
            PHP);

        Assert::same($residuals, [], 'A heredoc body is string content, never a comment token.');
    }

    #[Test]
    public function aCanonicalMarkerBetweenOtherCommentsIsStillFound(): void
    {
        $residuals = $this->scanner->scan('SurroundedTest.php', <<<'PHP'
            <?php

            /* unrelated note */
            /* laratesto-residual(code=LARAVEL_FAKE_UNSUPPORTED, rule=LaravelResidualDetectionRector, severity=manual): Mail::fake() — migrate manually */
            final class SurroundedTest
            {
            }
            PHP);

        Assert::count($residuals, 1, 'The canonical comment token among others keeps its finding.');

        $residual = $residuals[0] ?? null;
        \assert($residual instanceof Residual);

        Assert::same($residual->line, 4, 'The line must come from the comment token itself.');
    }
}
