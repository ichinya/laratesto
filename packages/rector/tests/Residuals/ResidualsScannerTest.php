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
    public function collectsMarkerWithFileLineRuleAndReason(): void
    {
        $residuals = $this->scanner->scan('SignupTest.php', <<<"PHP"
            <?php

            final class First
            {
            }

            /* laratesto-residual(rule=Laratesto\\Rector\\Rules\\LaravelResidualDetectionRector): Mail::fake() — no automatic conversion; migrate manually */
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
        Assert::same($residual->rule, 'Laratesto\Rector\Rules\LaravelResidualDetectionRector');
        Assert::same($residual->reason, 'Mail::fake() — no automatic conversion; migrate manually');
    }

    #[Test]
    public function rendersTableAndReport(): void
    {
        $residuals = $this->scanner->scan('A.php', "/* laratesto-residual(rule=RuleOne): first reason */\n");
        $residuals = [...$residuals, ...$this->scanner->scan('B.php', "line\n/* laratesto-residual(rule=RuleTwo): second reason */\n")];

        $table = $this->scanner->renderTable($residuals);

        Assert::true(str_contains($table, 'File'));
        Assert::true(str_contains($table, 'A.php'));
        Assert::true(str_contains($table, 'RuleTwo'));
        Assert::true(str_contains($table, 'second reason'));

        $report = $this->scanner->renderReport($residuals);

        Assert::true(str_contains($report, '- B.php:2 [RuleTwo] second reason'));
        Assert::count(explode("\n", $report), 6);
    }
}
