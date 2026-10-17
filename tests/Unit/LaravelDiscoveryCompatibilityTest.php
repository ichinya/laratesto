<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Tests\Support\TestoCompatibilityRunner;
use Testo\Assert;
use Testo\Test;

final class LaravelDiscoveryCompatibilityTest
{
    #[Test]
    public function classDiscoveryExcludesOnlyBridgeImplementations(): void
    {
        $run = TestoCompatibilityRunner::run('laravel-discovery');
        Assert::same($run['exit'], 0);
        Assert::same($run['json']['totals'], ['total' => 7, 'assertions' => 7, 'passed' => 7]);
        $names = [];
        foreach ($run['junit']->xpath('//testcase') as $case) {
            $names[] = (string) $case['name'];
        }
        sort($names);
        Assert::same($names, [
            'beginPhpUnitOutputCapture',
            'freeFunction',
            'inheritedTest',
            'neverTest',
            'ownTest',
            'ownTest',
            'setLaravelApplication',
        ]);
    }
}
