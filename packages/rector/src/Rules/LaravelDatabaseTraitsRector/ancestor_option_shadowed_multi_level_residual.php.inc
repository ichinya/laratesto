<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class LegacyCase extends FoundationTestCase
{
    protected array $connectionsToTransact = ['sqlite'];
}

abstract class TestCase extends LegacyCase
{
    protected array $connectionsToTransact = ['mariadb'];
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class DeepestShadowTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = ['pgsql'];

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

abstract class LegacyCase extends FoundationTestCase
{
    protected array $connectionsToTransact = ['sqlite'];
}

abstract class TestCase extends LegacyCase
{
    protected array $connectionsToTransact = ['mariadb'];
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTransact changes the migration scope: the trait always runs migrate:fresh on the default connection, while the attribute would refresh the selected connections - migrate it manually */
final class DeepestShadowTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = ['pgsql'];

    public function test_store(): void
    {
        $this->get('/store');
    }
}
