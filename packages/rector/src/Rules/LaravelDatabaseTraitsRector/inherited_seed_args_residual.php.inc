<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\Attributes\Seed;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[Seed(queueable: true)]
abstract class TestCase extends FoundationTestCase
{
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class SeedArgsTest extends \Tests\TestCase
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

#[Seed(queueable: true)]
abstract class TestCase extends FoundationTestCase
{
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): Laravel Seed attribute arguments on ancestor Tests\TestCase are not supported */
final class SeedArgsTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_store(): void {}
}
