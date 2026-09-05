<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;

final class NamedSelectionTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['secondary'];
}

final class MultipleSelectionsTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['sqlite', 'secondary'];
}

final class DuplicateSelectionTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['secondary', 'secondary'];
}

final class EmptySelectionTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [];
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTruncate changes the migration and seeding scope: the trait only truncates the selected connections while its first migrate:fresh and every later db:seed run on the default connection, but the attribute would migrate and seed every selected connection - migrate it manually */
final class NamedSelectionTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['secondary'];
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTruncate changes the migration and seeding scope: the trait only truncates the selected connections while its first migrate:fresh and every later db:seed run on the default connection, but the attribute would migrate and seed every selected connection - migrate it manually */
final class MultipleSelectionsTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['sqlite', 'secondary'];
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTruncate changes the migration and seeding scope: the trait only truncates the selected connections while its first migrate:fresh and every later db:seed run on the default connection, but the attribute would migrate and seed every selected connection - migrate it manually */
final class DuplicateSelectionTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['secondary', 'secondary'];
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTruncate has an unsupported literal shape */
final class EmptySelectionTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [];
}
