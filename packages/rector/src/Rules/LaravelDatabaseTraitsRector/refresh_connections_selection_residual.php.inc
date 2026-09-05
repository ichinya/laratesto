<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class NamedSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = ['secondary'];
}

final class MultipleSelectionsTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = ['sqlite', 'secondary'];
}

final class EmptySelectionTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTransact changes the migration scope: the trait always runs migrate:fresh on the default connection, while the attribute would refresh the selected connections - migrate it manually */
final class NamedSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = ['secondary'];
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTransact changes the migration scope: the trait always runs migrate:fresh on the default connection, while the attribute would refresh the selected connections - migrate it manually */
final class MultipleSelectionsTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = ['sqlite', 'secondary'];
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTransact has an unsupported literal shape */
final class EmptySelectionTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];
}
