<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    use RefreshDatabase;
}

final class DuplicateTraitTest extends TestCase
{
    use RefreshDatabase;

    public function test_store(): void
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

final class DuplicateTraitTest extends TestCase
{
    public function test_store(): void
    {
        $this->get('/store');
    }
}
