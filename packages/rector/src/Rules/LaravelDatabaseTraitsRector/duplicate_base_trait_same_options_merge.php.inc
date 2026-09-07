<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;
}

final class SeededDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_seeded(): void
    {
        $this->get('/store');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[\Laratesto\Attribute\RefreshDatabase(seed: true)]
abstract class TestCase extends FoundationTestCase
{
}

final class SeededDuplicateTest extends TestCase
{
    public function test_seeded(): void
    {
        $this->get('/store');
    }
}
