<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class TeardownTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_something(): void
    {
        $this->assertTrue(true);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class TeardownTest extends LaravelTestCase
{
    protected function setUpLaravel(): void
    {
    }

    protected function tearDownLaravel(): void
    {
    }

    #[\Testo\Test]
    public function test_something(): void
    {
        $this->assertTrue(true);
    }
}
