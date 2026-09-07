<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase;

final class TravelWormholeTest extends TestCase
{
    public function test_travel_days(): void
    {
        $this->travel(5)->days();
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase;

final class TravelWormholeTest extends \Laratesto\Testing\LaravelTestCase
{
    #[\Testo\Test]
    public function test_travel_days(): void
    {
        $this->travel(5)->days();
    }
}
