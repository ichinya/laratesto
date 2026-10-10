<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Tests\Support\TestoCompatibilityRunner;
use Testo\Assert;
use Testo\Test;

final class TestoPluginCompatibilityCliTest
{
    #[Test]
    public function effectiveSoloKeepsOwnershipAcrossSuspension(): void
    {
        $run = TestoCompatibilityRunner::run('fiber/positive');
        Assert::same(0, $run['exit']);
        Assert::same(['total' => 3, 'assertions' => 6, 'passed' => 3], $run['json']['totals']);
        $bodies = array_filter($run['events'], static fn($event) => $event['event'] === 'body');
        Assert::same(3, count(array_unique(array_column($bodies, 'owner'))));
    }

    #[Test]
    public function interleavingFailsBeforeAnySideEffectsAndStillReportsSkippedWork(): void
    {
        $run = TestoCompatibilityRunner::run('fiber/negative');
        Assert::same(1, $run['exit']);
        Assert::same(['total' => 7, 'assertions' => 0, 'aborted' => 4, 'skipped' => 3], $run['json']['totals']);
        Assert::same([], $run['events']);
        Assert::same(4, count($run['junit']->xpath('//testcase/error')));
        Assert::same(3, count($run['junit']->xpath('//testcase/skipped')));
        Assert::string($run['junit']->asXML())->contains('Schedule::Solo')->contains('RoundRobin')->contains('Random')->contains('blocked method skip');
        $filtered = TestoCompatibilityRunner::run('fiber/negative', arguments: ['--filter=Blocked::skipped']);
        Assert::same(0, $filtered['exit']);
        Assert::same(['total' => 1, 'assertions' => 0, 'skipped' => 1], $filtered['json']['totals']);
        Assert::same([], $filtered['events']);
    }

    #[Test]
    public function declarativeSkipIsInertAndRuntimeSkipCleansStartedApplications(): void
    {
        $run = TestoCompatibilityRunner::run('skip');
        Assert::same(0, $run['exit']);
        Assert::same(['total' => 8, 'assertions' => 2, 'skipped' => 6, 'passed' => 2], $run['json']['totals']);
        Assert::same(8, count($run['junit']->xpath('//testcase')));
        Assert::same(6, count($run['junit']->xpath('//testcase/skipped')));
        $counts = array_count_values(array_column($run['events'], 'event'));
        Assert::same(4, $counts['acquired']);
        Assert::same(4, $counts['booted']);
        Assert::same(4, $counts['setup']);
        Assert::same(3, $counts['teardown']);
        Assert::same(3, $counts['body']);
        Assert::same(1, $counts['mixed constructor']);
        foreach (array_keys($counts) as $name) { Assert::false(str_starts_with($name, 'unexpected'), $name); }
        Assert::string($run['junit']->asXML())->contains('class skip')->contains('inherited skip')->contains('method skip');
    }
}
