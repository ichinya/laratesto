<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\BlockedFiber;

use Testo\Test;
use Testo\Skip;
use Testo\Fiber\RunInFiber;
use Testo\Fiber\Schedule;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeClass;
use function Laratesto\Tests\Fixture\Compatibility\event;

#[RunInFiber(Schedule::RoundRobin)]
class Blocked extends \Laratesto\Testing\LaravelTestCase
{
    public function __construct() { event('unexpected constructor'); }
    #[BeforeClass]
    public static function hook(): void { event('unexpected class hook'); }
    public static function provider(): iterable { event('unexpected provider'); yield [1]; }
    #[Test, DataProvider('provider'), RunInFiber(Schedule::Solo)]
    public function first(int $value): void { event('unexpected body'); }
    #[Test]
    public function second(): void { event('unexpected body'); }
    #[Test, Skip('blocked method skip')]
    public function skipped(): void { event('unexpected body'); }
}

#[RunInFiber(Schedule::Random)]
final class RandomTest extends \Laratesto\Testing\LaravelTestCase
{
    #[Test]
    public function body(): void { event('unexpected body'); }
}

#[RunInFiber(Schedule::RoundRobin)]
abstract class InheritedParent extends \Laratesto\Testing\LaravelTestCase
{
    #[Test]
    public function inherited(): void { event('unexpected body'); }
}
final class InheritedChild extends InheritedParent {}

#[RunInFiber(Schedule::RoundRobin), Skip('all skipped Fiber')]
final class SkippedFiber extends \Laratesto\Testing\LaravelTestCase
{
    public function __construct() { event('unexpected constructor'); }
    #[BeforeClass]
    public static function hook(): void { event('unexpected class hook'); }
    #[Test]
    public function first(): void { event('unexpected body'); }
    #[Test]
    public function second(): void { event('unexpected body'); }
}
