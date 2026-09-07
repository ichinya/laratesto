<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

trait SeedReader
{
    public function observedSeed(): bool
    {
        return $this->{'seed'};
    }
}

abstract class TestCase extends FoundationTestCase
{
    use SeedReader;

    protected bool $seed = true;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class AncestorTraitReadTest extends \Tests\TestCase
{
    use RefreshDatabase;

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

trait SeedReader
{
    public function observedSeed(): bool
    {
        return $this->{'seed'};
    }
}

abstract class TestCase extends FoundationTestCase
{
    use SeedReader;

    protected bool $seed = true;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $seed is read by project code in Tests\SeedReader and requires manual migration */
final class AncestorTraitReadTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
