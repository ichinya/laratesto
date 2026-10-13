<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\Attributes\Seed;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[Seed]
abstract class TestCase extends FoundationTestCase
{
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class InheritedSeedTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_store(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\Attributes\Seed;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[Seed]
abstract class TestCase extends FoundationTestCase
{
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

#[\Laratesto\Attribute\RefreshDatabase(seed: true)]
final class InheritedSeedTest extends \Tests\TestCase
{
    public function test_store(): void {}
}
