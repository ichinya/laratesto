<?php

declare(strict_types=1);

namespace Tests;

use App\Database\DemoSeeder;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[Seeder(DemoSeeder::class)]
#[Seeder(DemoSeeder::class)]
abstract class TestCase extends FoundationTestCase
{
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class DuplicateSeederTest extends \Tests\TestCase
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

#[Seeder(DemoSeeder::class)]
#[Seeder(DemoSeeder::class)]
abstract class TestCase extends FoundationTestCase
{
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): duplicate Laravel Seeder attributes on ancestor Tests\TestCase require manual migration */
final class DuplicateSeederTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_store(): void {}
}
