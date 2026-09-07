<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[\Laratesto\Attribute\RefreshDatabase]
abstract class TestCase extends FoundationTestCase
{
}

final class LateMigratedTest extends TestCase
{
    use RefreshDatabase;

    public function test_ok(): void
    {
        $this->get('/store');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[\Laratesto\Attribute\RefreshDatabase]
abstract class TestCase extends FoundationTestCase
{
}

final class LateMigratedTest extends TestCase
{
    public function test_ok(): void
    {
        $this->get('/store');
    }
}
