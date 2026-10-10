<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Fiber;

use Testo\Assert;
use Testo\Test;
use Testo\Fiber\RunInFiber;
use Testo\Fiber\Schedule;
use function Laratesto\Tests\Fixture\Compatibility\event;

#[RunInFiber(Schedule::Solo)]
final class SoloTest extends \Laratesto\Testing\LaravelTestCase
{
    private function body(): void
    {
        $owner = $this->app()->make('compatibility.owner');
        event('body', ['owner' => $owner]);
        Assert::notNull(\Fiber::getCurrent());
        \Fiber::suspend();
        Assert::same($owner, app('compatibility.owner'));
    }
    #[Test]
    public function first(): void { $this->body(); }
    #[Test]
    public function second(): void { $this->body(); }
}

final class MethodTest extends \Laratesto\Testing\LaravelTestCase
{
    #[Test, RunInFiber(Schedule::Random)]
    public function methodArgumentStillRunsSolo(): void
    {
        $owner = $this->app()->make('compatibility.owner');
        event('body', ['owner' => $owner]);
        Assert::notNull(\Fiber::getCurrent());
        \Fiber::suspend();
        Assert::same($owner, app('compatibility.owner'));
    }
}
