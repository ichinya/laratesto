<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    use RefreshDatabase;
}

final class WrappedTest extends TestCase
{
    use DatabaseTransactions;

    public function test_wrapped(): void
    {
        \DB::table('things')->insert(['name' => 'rolled-back']);
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

#[\Laratesto\Attribute\DatabaseTransactions]
final class WrappedTest extends TestCase
{
    public function test_wrapped(): void
    {
        \DB::table('things')->insert(['name' => 'rolled-back']);
    }
}
