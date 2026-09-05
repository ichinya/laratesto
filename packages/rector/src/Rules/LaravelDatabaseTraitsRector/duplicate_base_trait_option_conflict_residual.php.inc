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

final class ConflictingSeedTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    public function test_other_seeding(): void
    {
        $this->get('/store');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

#[\Laratesto\Attribute\RefreshDatabase(seed: true)]
abstract class TestCase extends FoundationTestCase
{
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): ancestor Tests\TestCase also uses Illuminate\Foundation\Testing\RefreshDatabase and the database configurations cannot be merged exactly (seed=false here, seed=true on the ancestor) - remove the duplicated trait configuration manually */
final class ConflictingSeedTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    public function test_other_seeding(): void
    {
        $this->get('/store');
    }
}
