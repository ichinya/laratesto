<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait BindsClock
{
    public function bindClock(): void
    {
        // Ordinary helper has no dependency on the source test base.
    }
}

final class SafeTraitTest extends TestCase
{
    use BindsClock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache');
    }

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait BindsClock
{
    public function bindClock(): void
    {
        // Ordinary helper has no dependency on the source test base.
    }
}

final class SafeTraitTest extends \Laratesto\Testing\LaravelTestCase
{
    use BindsClock;

    protected function setUpLaravel(): void
    {
        $this->make('cache');
    }

    #[\Testo\Test]
    public function test_ok(): void {}
}
