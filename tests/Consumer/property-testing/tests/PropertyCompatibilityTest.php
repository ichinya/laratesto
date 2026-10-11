<?php

declare(strict_types=1);

namespace LaratestoConsumer;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Laratesto\Attribute\RefreshDatabase;
use Laratesto\Testing\LaravelTestCase;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

#[RefreshDatabase]
final class PropertyCompatibilityTest extends LaravelTestCase
{
    private string $owner;

    protected function setUpLaravel(): void
    {
        $this->owner = bin2hex(random_bytes(8));
        $this->app()->instance('pbt.owner', $this->owner);
        $this->record('setup');
    }

    protected function tearDownLaravel(): void { $this->record('teardown'); }

    private function record(string $event, array $data = []): void
    {
        file_put_contents(getenv('LARATESTO_PBT_DIRECTORY').'/events.jsonl', json_encode([
            'event' => $event, 'owner' => $this->owner, ...$data,
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }

    private function trial(string $property, array $arguments, bool $reset): void
    {
        $this->record('trial', [
            'property' => $property, 'arguments' => $arguments,
            'container' => $this->app()->bound('pbt.leak'), 'rows' => DB::table('things')->count(),
            'guest' => Auth::guest(), 'cache' => Cache::get('pbt.leak'), 'clock' => Carbon::hasTestNow(),
            'facadeOwner' => Facade::getFacadeApplication()->make('pbt.owner'),
        ]);
        Assert::same($this->owner, $this->app()->make('pbt.owner'));
        if ($reset) {
            // The application and Laravel hooks surround the whole property.
            // Reset trial mutations explicitly, including shrink/replay inputs.
            DB::table('things')->delete();
            $this->app()->forgetInstance('pbt.leak');
            Cache::forget('pbt.leak');
            $this->actingAsGuest();
            Carbon::setTestNow();
            Assert::same(0, DB::table('things')->count());
            Assert::true(Auth::guest());
            Assert::false(Carbon::hasTestNow());
        }
        DB::table('things')->insert(['name' => $property]);
        $this->app()->instance('pbt.leak', true);
        Cache::put('pbt.leak', true);
        $this->actingAs(new \App\Models\TestUser(123, 'Synthetic property user'));
        Carbon::setTestNow('2020-01-01');
    }

    /** @param int<1, 40> $attempt */
    #[Test, Property(generators: 'provide', auto: true, runs: 5, maxShrinks: 16, maxDiscards: 5, timeoutMs: 5000, budgetMs: 30000, flakyReplays: 0)]
    public function explicitGeneratorAndAutoBounds(float $multiplier, int $attempt): void
    {
        $this->trial(__FUNCTION__, [$multiplier, $attempt], true);
        Assert::true($multiplier >= 1.0 && $multiplier <= 4.0);
        Assert::true($attempt >= 1 && $attempt <= 40);
    }

    public static function provide(): array { return ['multiplier' => Gen::floatBetween(1.0, 4.0)]; }

    /** @param int<-10, 10> $value */
    #[Test, Covers(ProbeMath::class), Property(auto: true, runs: 5, maxShrinks: 16, maxDiscards: 5, timeoutMs: 5000, budgetMs: 30000, flakyReplays: 0)]
    public function sharedStateIsObserved(int $value): void
    {
        $this->trial(__FUNCTION__, [$value], false);
        Assert::same(abs($value), ProbeMath::magnitude($value));
    }

    public static function sharedStateIsObservedExamples(): array { return [[-2], [2]]; }

    /** @param int<5, 20> $value */
    #[Test, Property(auto: true, runs: 5, maxShrinks: 16, maxDiscards: 5, timeoutMs: 5000, budgetMs: 30000, flakyReplays: 0)]
    public function failingProperty(int $value): void
    {
        $this->trial(__FUNCTION__, [$value], true);
        Assert::true($value < 5, 'Intentional falsification for shrinking and replay.');
    }

    /** @param int<1, 40> $value */
    #[Test, Property(auto: true, runs: 5, maxShrinks: 16, maxDiscards: 3, timeoutMs: 5000, budgetMs: 30000, flakyReplays: 0)]
    public function allTrialsSkip(int $value): void
    {
        $this->trial(__FUNCTION__, [$value], true);
        throw new SkipTest('Synthetic missing property dependency');
    }

    #[Test]
    public function followingOrdinaryTest(): void
    {
        $this->record('ordinary');
        Assert::false($this->app()->bound('pbt.leak'));
        Assert::same(0, DB::table('things')->count());
        Assert::true(Auth::guest());
        Assert::null(Cache::get('pbt.leak'));
        Assert::false(Carbon::hasTestNow());
    }
}
