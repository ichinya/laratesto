<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

trait SeedOptions
{
    protected bool $seed = true;
}

abstract class TestCase extends FoundationTestCase
{
    use SeedOptions;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class AncestorTraitTest extends \Tests\TestCase
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

trait SeedOptions
{
    protected bool $seed = true;
}

abstract class TestCase extends FoundationTestCase
{
    use SeedOptions;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $seed on trait Tests\SeedOptions requires manual migration */
final class AncestorTraitTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
