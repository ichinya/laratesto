<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Tests\Support\TestoCompatibilityRunner;
use Testo\Assert;
use Testo\Test;

final class TestoCompatibilityRunnerTest
{
    #[Test]
    public function capturesNativePassFailureExitAndBothReports(): void
    {
        $run = TestoCompatibilityRunner::run('smoke');
        Assert::same(1, $run['exit']);
        Assert::same(['total' => 2, 'assertions' => 2, 'passed' => 1, 'failed' => 1], $run['json']['totals']);
        Assert::same(2, count($run['junit']->xpath('//testcase')));
        Assert::same(1, count($run['junit']->xpath('//testcase/failure')));
        Assert::string($run['json']['failures'][0]['test'])->contains('SmokeTest::fails');
        Assert::string($run['stdout'])->contains('compatibility negative control');
    }
}
