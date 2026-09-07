<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class TeardownTest extends TestCase
{
    #[\Testo\Lifecycle\BeforeTest, \Deprecated]
    protected function setUp(): void
    {
        parent::setUp();
    }

    #[\Testo\Lifecycle\AfterTest]
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_something(): void
    {
        $this->travel(5);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class TeardownTest extends \Laratesto\Testing\LaravelTestCase
{
    #[\Deprecated]
    protected function setUpLaravel(): void
    {
    }

    protected function tearDownLaravel(): void
    {
    }

    #[\Testo\Test]
    public function test_something(): void
    {
        $this->travel(5);
    }
}
