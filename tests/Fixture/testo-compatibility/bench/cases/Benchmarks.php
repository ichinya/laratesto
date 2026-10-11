<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Bench;

use Testo\Assert;
use Testo\Bench;
use Testo\Test;
use function Laratesto\Tests\Fixture\Compatibility\event;

function observe(string $variant, int $a, int $b): void
{
    $owner = getenv('LARATESTO_BENCH_LARAVEL') === '1' ? app('compatibility.owner') : null;
    event('call', compact('variant', 'a', 'b', 'owner'));
}

#[Bench(['add' => __NAMESPACE__.'\alternative', 'array' => __NAMESPACE__.'\arrayAlternative'], arguments: [1, 2], warmup: 2, calls: 3, iterations: 2, tolerance: INF)]
#[Bench(['add' => __NAMESPACE__.'\alternative', 'array' => __NAMESPACE__.'\arrayAlternative'], arguments: [1, 2], warmup: 2, calls: 3, iterations: 2, tolerance: INF)]
function current(int $a, int $b): int { observe('normal current', $a, $b); return $a + $b; }
function alternative(int $a, int $b): int { observe('normal add', $a, $b); return $a + $b; }
function arrayAlternative(int $a, int $b): int { observe('normal array', $a, $b); return array_sum([$a, $b]); }

#[Bench(['alternative' => __NAMESPACE__.'\controlledAlternative'], arguments: [1, 2], warmup: 1, calls: 1, iterations: 2, tolerance: 0.0)]
function controlledFailure(int $a, int $b): int { observe('controlled current', $a, $b); return $a + $b; }
function controlledAlternative(int $a, int $b): int { observe('controlled alternative', $a, $b); return $a + $b; }

#[Bench(['bad' => __NAMESPACE__.'\badAlternative'], arguments: [1, 2], warmup: 1, calls: 1, iterations: 2, tolerance: INF)]
function errorCurrent(int $a, int $b): int { observe('error current', $a, $b); return $a + $b; }
function badAlternative(int $a, int $b): int { observe('error alternative', $a, $b); throw new \RuntimeException('benchmark alternative error'); }

#[Test]
function functionalEquivalence(): void { Assert::same(3, array_sum([1, 2])); }
