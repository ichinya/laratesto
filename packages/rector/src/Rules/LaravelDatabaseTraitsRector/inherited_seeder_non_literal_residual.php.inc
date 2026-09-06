<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[Seeder(self::SEEDER_CLASS)]
abstract class TestCase extends FoundationTestCase
{
    public const SEEDER_CLASS = \stdClass::class;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class NonLiteralSeederTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_store(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[Seeder(self::SEEDER_CLASS)]
abstract class TestCase extends FoundationTestCase
{
    public const SEEDER_CLASS = \stdClass::class;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): Laravel Seeder attribute on ancestor Tests\TestCase must contain one literal class */
final class NonLiteralSeederTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_store(): void {}
}
