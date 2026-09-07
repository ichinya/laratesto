<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

trait LocalSeedReader
{
    public function observedSeed(): bool
    {
        return $this->seed;
    }
}

abstract class TestCase extends FoundationTestCase
{
    protected bool $seed = true;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\LocalSeedReader;

final class ChildTraitReadTest extends \Tests\TestCase
{
    use RefreshDatabase;
    use LocalSeedReader;

    protected bool $seed = false;

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

trait LocalSeedReader
{
    public function observedSeed(): bool
    {
        return $this->seed;
    }
}

abstract class TestCase extends FoundationTestCase
{
    protected bool $seed = true;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\LocalSeedReader;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $seed is read by project code in Tests\LocalSeedReader and requires manual migration */
final class ChildTraitReadTest extends \Tests\TestCase
{
    use RefreshDatabase;
    use LocalSeedReader;

    protected bool $seed = false;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
