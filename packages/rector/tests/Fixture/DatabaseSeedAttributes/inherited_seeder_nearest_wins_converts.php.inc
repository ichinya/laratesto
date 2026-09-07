<?php

declare(strict_types=1);

namespace Tests;

use App\Database\DemoSeeder;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[Seeder(OtherSeeder::class)]
abstract class GrandTestCase extends FoundationTestCase
{
}

#[Seeder(DemoSeeder::class)]
abstract class TestCase extends GrandTestCase
{
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class NearestSeederTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_store(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use App\Database\DemoSeeder;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[Seeder(OtherSeeder::class)]
abstract class GrandTestCase extends FoundationTestCase
{
}

#[Seeder(DemoSeeder::class)]
abstract class TestCase extends GrandTestCase
{
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

#[\Laratesto\Attribute\RefreshDatabase(seeder: \App\Database\DemoSeeder::class)]
final class NearestSeederTest extends \Tests\TestCase
{
    public function test_store(): void {}
}
