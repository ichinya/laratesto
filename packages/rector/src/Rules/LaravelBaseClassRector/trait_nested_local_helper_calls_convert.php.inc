<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Clock
{
    protected function clock(): int { return 42; }
}

trait ChecksClock
{
    use Clock { clock as currentClock; }

    #[\Testo\Test]
    public function test_clock(): void
    {
        if ($this->currentClock() !== 42 || self::clock() !== 42) {
            throw new \RuntimeException('Clock changed');
        }
    }
}

final class ClockTest extends \Illuminate\Foundation\Testing\TestCase
{
    use ChecksClock;
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Clock
{
    protected function clock(): int { return 42; }
}

trait ChecksClock
{
    use Clock { clock as currentClock; }

    #[\Testo\Test]
    public function test_clock(): void
    {
        if ($this->currentClock() !== 42 || self::clock() !== 42) {
            throw new \RuntimeException('Clock changed');
        }
    }
}

final class ClockTest extends \Laratesto\Testing\LaravelTestCase
{
    use ChecksClock;
}
