<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Tests\Support\TestoCompatibilityRunner;
use Testo\Assert;
use Testo\Test;

final class TestoInlineCompatibilityTest
{
    #[Test]
    public function expectedValuesDefaultConstructorsAndRequiredDependencyBoundary(): void
    {
        foreach (['0', '1'] as $laravel) {
            $run = TestoCompatibilityRunner::run('inline', ['LARATESTO_INLINE_LARAVEL' => $laravel, 'LARATESTO_INLINE_CALLBACKS' => '0']);
            Assert::same(1, $run['exit']);
            Assert::same(['total' => 6, 'assertions' => 5, 'passed' => 4, 'failed' => 1, $laravel === '1' ? 'aborted' : 'error' => 1], $run['json']['totals']);
            Assert::same(6, count($run['junit']->xpath('//testcase')));
            $counts = array_count_values(array_column($run['events'], 'event'));
            Assert::same(1, $counts['constructor']);
            Assert::false(isset($counts['dependency constructor']), 'The native case constructor does not resolve required arguments through Laravel DI.');
            if ($laravel === '1') {
                Assert::same(5, $counts['booted']);
                $bodies = array_filter($run['events'], static fn($event) => $event['event'] === 'body');
                Assert::same(5, count(array_unique(array_column($bodies, 'owner'))));
            } else {
                Assert::same(5, $counts['plain body']);
                Assert::false(isset($counts['acquired']));
            }
        }
    }

    #[Test]
    public function callbacksAreSelectedBeforeDiscoveryOnlyOnPhp85(): void
    {
        $run = TestoCompatibilityRunner::run('inline', ['LARATESTO_INLINE_LARAVEL' => '1', 'LARATESTO_INLINE_CALLBACKS' => '1']);
        if (PHP_VERSION_ID < 80500) {
            Assert::same(6, $run['json']['totals']['total']);
            return;
        }
        Assert::same(1, $run['exit']);
        Assert::same(['total' => 2, 'assertions' => 3, 'passed' => 1, 'failed' => 1], $run['json']['totals']);
        Assert::same(2, count($run['junit']->xpath('//testcase')));
        $bodies = array_filter($run['events'], static fn($event) => $event['event'] === 'body');
        Assert::same(2, count(array_unique(array_column($bodies, 'owner'))));
        Assert::string(json_encode($run['json']['failures']))->contains('callback negative control');
    }
}
