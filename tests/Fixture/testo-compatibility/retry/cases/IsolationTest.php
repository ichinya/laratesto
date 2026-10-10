<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Retry;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laratesto\Attribute\DatabaseMigrations;
use Laratesto\Attribute\DatabaseTruncation;
use Laratesto\Attribute\DatabaseTransactions;
use Laratesto\Attribute\RefreshDatabase;
use Testo\Assert;
use Testo\Repeat;
use Testo\Retry;
use Testo\Test;
use function Laratesto\Tests\Fixture\Compatibility\event;

final class IsolationTest extends \Laratesto\Testing\LaravelTestCase
{
    private array $owners = [];
    private bool $secondarySkip = false;
    private int $combinedRuns = 0;

    protected function setUpLaravel(): void
    {
        event('setup', ['owner' => $this->app()->make('compatibility.owner')]);
        Assert::false($this->app()->bound('compatibility.leak'));
        Assert::true(Auth::guest());
        Assert::null(Cache::get('compatibility.leak'));
        Assert::false(\Carbon\Carbon::hasTestNow());
    }

    protected function tearDownLaravel(): void
    {
        event('teardown', ['owner' => $this->app()->make('compatibility.owner')]);
        if ($this->secondarySkip) {
            $this->secondarySkip = false;
            throw new \Testo\Core\Exception\SkipTest('secondary retry teardown');
        }
    }

    public function clearLaravelApplicationReferences(): void
    {
        parent::clearLaravelApplicationReferences();
        event('released');
    }

    private function body(): void
    {
        $owner = $this->app()->make('compatibility.owner');
        Assert::false(in_array($owner, $this->owners, true), 'Every actual execution must own a new application.');
        $this->owners[] = $owner;
        event('body', ['owner' => $owner]);
        Assert::same(0, DB::table('things')->count());
        DB::table('things')->insert(['name' => $owner]);
        $this->app()->instance('compatibility.leak', true);
        Cache::put('compatibility.leak', true);
        $this->actingAs(new \App\Models\TestUser(123, 'Compatibility'));
        \Carbon\Carbon::setTestNow('2020-01-01');
        $mock = \Mockery::mock();
        $mock->shouldReceive('ping')->once()->andReturn(true);
        Assert::true($mock->ping());
    }

    #[Test, RefreshDatabase, Repeat(times: 3)]
    public function repeatRefresh(): void { $this->body(); }

    #[Test, RefreshDatabase, Retry(maxAttempts: 2), Repeat(times: 2)]
    public function combinedFlaky(): void
    {
        $this->body();
        if (++$this->combinedRuns === 1) {
            $this->secondarySkip = true;
            Assert::true(false, 'first combined body failure');
        }
    }

    #[Test, RefreshDatabase, Retry(maxAttempts: 2)]
    public function exhausted(): void
    {
        $this->body();
        $this->secondarySkip = true;
        Assert::true(false, 'primary exhausted body failure');
    }

    #[Test, RefreshDatabase, Repeat(times: 3), Retry(maxAttempts: 2)]
    public function runtimeSkip(): void { $this->body(); throw new \Testo\Core\Exception\SkipTest('runtime body skip'); }

    #[Test, DatabaseMigrations, Repeat(times: 2)]
    public function repeatMigrations(): void { $this->body(); }

    #[Test, DatabaseTruncation, Repeat(times: 2)]
    public function repeatTruncation(): void { $this->body(); }

    #[Test, RefreshDatabase(connections: ['sqlite', 'secondary']), DatabaseTransactions(connections: ['sqlite', 'secondary']), Repeat(times: 2)]
    public function repeatStackedTransactions(): void
    {
        $this->body();
        foreach (['sqlite', 'secondary'] as $name) {
            Assert::same(2, DB::connection($name)->transactionLevel());
        }
        Assert::same(0, DB::connection('secondary')->table('things')->count());
        DB::connection('secondary')->table('things')->insert(['name' => 'secondary']);
    }

    #[Test, RefreshDatabase]
    public function followingOrdinaryTest(): void { $this->body(); }
}
