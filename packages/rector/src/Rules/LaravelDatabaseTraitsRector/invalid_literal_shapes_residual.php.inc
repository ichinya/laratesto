<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class InvalidSeedShapeTest extends TestCase
{
    use RefreshDatabase;

    protected string $seed = 'yes';
}

final class InvalidConnectionsShapeTest extends TestCase
{
    use DatabaseTruncation;

    protected string $connectionsToTruncate = 'sqlite';
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $seed has an unsupported literal shape */
final class InvalidSeedShapeTest extends TestCase
{
    use RefreshDatabase;

    protected string $seed = 'yes';
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTruncate has an unsupported literal shape */
final class InvalidConnectionsShapeTest extends TestCase
{
    use DatabaseTruncation;

    protected string $connectionsToTruncate = 'sqlite';
}
