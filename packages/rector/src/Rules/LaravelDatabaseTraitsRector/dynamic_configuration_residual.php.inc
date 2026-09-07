<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class DynamicDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact;
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTransact must be a non-static literal */
final class DynamicDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact;
}
