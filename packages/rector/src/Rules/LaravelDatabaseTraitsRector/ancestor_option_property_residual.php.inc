<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    protected bool $seed = true;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class AncestorSeedTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = ['mariadb'];

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

abstract class TestCase extends FoundationTestCase
{
    protected bool $seed = true;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $seed on ancestor Tests\TestCase requires manual migration */
final class AncestorSeedTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = ['mariadb'];

    public function test_store(): void
    {
        $this->get('/store');
    }
}
