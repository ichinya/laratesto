<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Skip;

use Laratesto\Attribute\RefreshDatabase;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Fiber\RunInFiber;
use Testo\Fiber\Schedule;
use Testo\Lifecycle\BeforeClass;
use Testo\Repeat;
use Testo\Retry;
use Testo\Skip;
use Testo\Test;
use function Laratesto\Tests\Fixture\Compatibility\event;

#[Skip('class skip')]
final class EntirelySkipped extends \Laratesto\Testing\LaravelTestCase
{
    public function __construct() { event('unexpected constructor'); }
    #[BeforeClass]
    public static function classHook(): void { event('unexpected class hook'); }
    public static function provider(): iterable { event('unexpected provider'); yield [1]; }
    #[Test, DataProvider('provider'), Retry, Repeat, RunInFiber(Schedule::RoundRobin), RefreshDatabase]
    public function composed(int $value): void { event('unexpected body'); }
    #[Test]
    public function other(): void { event('unexpected body'); }
}

#[Skip('inherited skip')]
abstract class SkippedParent extends \Laratesto\Testing\LaravelTestCase
{
    public function __construct() { event('unexpected constructor'); }
    #[Test]
    public function inherited(): void { event('unexpected body'); }
}
final class SkippedChild extends SkippedParent {}

final class MixedTest extends \Laratesto\Testing\LaravelTestCase
{
    public function __construct() { event('mixed constructor'); }
    protected function setUpLaravel(): void { event('setup'); }
    protected function tearDownLaravel(): void { event('teardown'); }
    public static function provider(): iterable { event('unexpected provider'); yield [1]; }
    #[Test, Skip('method skip'), DataProvider('provider'), Retry, Repeat, RunInFiber, RefreshDatabase]
    public function skipped(int $value): void { event('unexpected body'); }
    #[Test]
    public function runs(): void { event('body'); Assert::notNull($this->app()); }
}

final class RuntimeSkipTest extends \Laratesto\Testing\LaravelTestCase
{
    private int $setups = 0;
    protected function setUpLaravel(): void
    {
        event('setup');
        if (++$this->setups === 1) { throw new \Testo\Core\Exception\SkipTest('runtime setup skip'); }
    }
    protected function tearDownLaravel(): void { event('teardown'); }
    #[Test]
    public function setupSkip(): void { event('unexpected body'); }
    #[Test]
    public function bodySkip(): void { event('body'); throw new \Testo\Core\Exception\SkipTest('runtime body skip'); }
    #[Test]
    public function cleanFollowingTest(): void { event('body'); Assert::notNull($this->app()); }
}
