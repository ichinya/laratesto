<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Tests\Support\TestoCompatibilityRunner;
use Testo\Assert;
use Testo\Test;

final class TestoBenchCompatibilityTest
{
    #[Test]
    public function nativeMeasurementsControlledVerdictAndErrorsKeepTheirReports(): void
    {
        foreach (['0', '1'] as $laravel) {
            $run = TestoCompatibilityRunner::run('bench', ['LARATESTO_BENCH_LARAVEL' => $laravel]);
            Assert::same(1, $run['exit']);
            Assert::same(['total' => 5, 'assertions' => 4, 'passed' => 3, 'failed' => 1, 'error' => 1], $run['json']['totals']);
            Assert::same(3, count($run['json']['benchmarks']));
            Assert::same(5, count($run['junit']->xpath('//testcase')));
            Assert::true(count($run['junit']->xpath('//property[starts-with(@name,"bench.")]')) > 0);
            Assert::string(json_encode($run['json']['failures']))->contains('benchmark alternative error')->contains('alternative');
            $calls = array_filter($run['events'], static fn($event) => $event['event'] === 'call' && str_starts_with($event['variant'], 'normal'));
            Assert::same(48, count($calls), 'Each of three variants gets 2 warmups + 3 calls × 2 iterations, for two attributes.');
            foreach ($calls as $call) { Assert::same([1, 2], [$call['a'], $call['b']]); }
            $counts = array_count_values(array_column($run['events'], 'event'));
            if ($laravel === '1') {
                Assert::same(5, $counts['booted']);
                Assert::same(2, count(array_unique(array_column($calls, 'owner'))), 'Measured loops share their attribute application.');
            } else {
                Assert::false(isset($counts['acquired']));
            }
        }
    }
}
