<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Tests\Support\TestoCompatibilityRunner;
use Testo\Assert;
use Testo\Test;

final class TestoDiscoveryCompatibilityTest
{
    #[Test]
    public function directoryExclusionAndExplicitFileInclusionPreserveDiscovery(): void
    {
        foreach (['0' => 1, '1' => 2] as $includeFile => $expected) {
            $run = TestoCompatibilityRunner::run('discovery', ['LARATESTO_DISCOVERY_INCLUDE_FILE' => (string) $includeFile]);
            Assert::same(0, $run['exit']);
            Assert::same(['total' => $expected, 'assertions' => $expected, 'passed' => $expected], $run['json']['totals']);
            Assert::same($expected, count($run['junit']->xpath('//testcase')));
            $counts = array_count_values(array_column($run['events'], 'event'));
            Assert::same(1, $counts['included']);
            Assert::same($expected - 1, $counts['explicit file'] ?? 0);
        }
    }
}
