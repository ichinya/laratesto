<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase;

final class AssertEmptyLiteralArrayTest extends TestCase
{
    public function test_emptiness_on_literal_arrays(): void
    {
        $this->assertEmpty(['a' => 1]);
        $this->assertNotEmpty([1, 2], 'items present');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase;

final class AssertEmptyLiteralArrayTest extends \Laratesto\Testing\LaravelTestCase
{
    #[\Testo\Test]
    public function test_emptiness_on_literal_arrays(): void
    {
        $this->assertEmpty(['a' => 1]);
        $this->assertNotEmpty([1, 2], 'items present');
    }
}
