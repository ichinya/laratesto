<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Tests\Support\TestoCompatibilityRunner;
use Testo\Assert;
use Testo\Test;

final class HttpClientCompatibilityTest
{
    #[Test]
    public function nativeExecutionSharesStateAndRevokesEachAttemptAcrossBothSuites(): void
    {
        $run = TestoCompatibilityRunner::run('http-client');
        Assert::same(0, $run['exit'], $run['stdout'].$run['stderr']);
        Assert::same(8, $run['json']['totals']['total']);
        Assert::same(7, $run['json']['totals']['passed']);
        Assert::same(1, $run['json']['totals']['flaky']);
        $closed = array_values(array_filter($run['events'], static fn($event) => $event['event'] === 'closed'));
        Assert::same(8, count($closed));
        Assert::same([true], array_values(array_unique(array_column($closed, 'closed'))));
    }

    #[Test]
    public function aSelectedSuiteDoesNotDependOnAnotherSuiteBeingExecuted(): void
    {
        $run = TestoCompatibilityRunner::run('http-client', arguments: ['--suite=secondary']);
        Assert::same(0, $run['exit'], $run['stdout'].$run['stderr']);
        Assert::same(1, $run['json']['totals']['total']);
        Assert::same(1, $run['json']['totals']['passed']);
        Assert::same([true], array_column($run['events'], 'closed'));
    }

    #[Test]
    public function failuresRevokeTheClientWithoutReplacingThePrimaryFailure(): void
    {
        foreach (['boot', 'injection', 'setup', 'body', 'cleanup'] as $stage) {
            $run = TestoCompatibilityRunner::run('http-client', ['LARATESTO_HTTP_FAULT' => $stage]);
            Assert::same(1, $run['exit'], $stage);
            Assert::same(2, $run['json']['totals']['total'], $stage);
            Assert::same(1, $run['json']['totals']['passed'], $stage);
            $failure = $run['json']['failures'][0];
            Assert::string($failure['message'])->contains(in_array($stage, ['body', 'cleanup'], true) ? 'primary body fault' : 'primary '.$stage.' fault');
            if (in_array($stage, ['body', 'cleanup'], true)) {
                Assert::string(json_encode($failure))->contains('secondary teardown fault');
            }
            if ($stage === 'cleanup') {
                Assert::string(json_encode($failure))->contains('secondary cleanup fault');
            }
            Assert::same([true, true], array_column($run['events'], 'closed'));
        }
    }
}
