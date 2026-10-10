<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Inline;

use Testo\Inline\TestInline;
use function Laratesto\Tests\Fixture\Compatibility\event;

function observe(): void
{
    if (getenv('LARATESTO_INLINE_LARAVEL') === '1') {
        if (app()->bound('inline.leak')) { throw new \RuntimeException('Inline application was reused'); }
        event('body', ['owner' => app('compatibility.owner')]);
        app()->instance('inline.leak', true);
    } else {
        event('plain body');
    }
}

#[TestInline([1, 1], 2)]
#[TestInline([40, 2], 42)]
function sum(int $a, int $b): int { observe(); return $a + $b; }

final class StaticInline
{
    #[TestInline([2, 3], 6)]
    #[TestInline([1, 2], 99)]
    private static function multiply(int $a, int $b): int { observe(); return $a * $b; }
}

final class InstanceInline
{
    public function __construct(private int $base = 10) { event('constructor'); }
    #[TestInline([2], 12)]
    public function add(int $value): int { observe(); return $this->base + $value; }
}

final class NativeDependency { public int $base = 20; }
final class DependencyInline
{
    public function __construct(private NativeDependency $dependency) { event('dependency constructor'); }
    #[TestInline([1], 21)]
    public function add(int $value): int { observe(); return $this->dependency->base + $value; }
}
