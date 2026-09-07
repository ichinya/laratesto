<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\Attributes\Seed;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[Seed]
#[Seed]
abstract class TestCase extends FoundationTestCase
{
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class DuplicateSeedTest extends \Tests\TestCase
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
#[Seed]
abstract class TestCase extends FoundationTestCase
{
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): duplicate Laravel Seed attributes on ancestor Tests\TestCase require manual migration */
final class DuplicateSeedTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_store(): void {}
}
