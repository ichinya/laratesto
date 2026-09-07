<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;

final class KeyedTablesTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [null];

    protected array $tablesToTruncate = ['sqlite' => ['things']];
}

final class KeyedExceptTest extends TestCase
{
    use DatabaseTruncation;

    protected array $exceptTables = ['sqlite' => ['audit_entries']];
}

final class NamedConnectionKeyedTablesTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['secondary'];

    protected array $tablesToTruncate = ['secondary' => ['orders']];
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $tablesToTruncate keyed by connection name changes the truncation selection: the trait looks the map up with the null default selector, falls back to the whole map that matches no table and truncates nothing, while the attribute would truncate the listed tables on the resolved connection - migrate it manually */
final class KeyedTablesTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [null];

    protected array $tablesToTruncate = ['sqlite' => ['things']];
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $exceptTables keyed by connection name changes the exclusion selection: the trait looks the map up with the null default selector, falls back to the whole map that matches no table and excludes nothing beyond the migrations table, while the attribute would exclude the listed tables on the resolved connection - migrate it manually */
final class KeyedExceptTest extends TestCase
{
    use DatabaseTruncation;

    protected array $exceptTables = ['sqlite' => ['audit_entries']];
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTruncate changes the migration and seeding scope: the trait only truncates the selected connections while its first migrate:fresh and every later db:seed run on the default connection, but the attribute would migrate and seed every selected connection - migrate it manually */
final class NamedConnectionKeyedTablesTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['secondary'];

    protected array $tablesToTruncate = ['secondary' => ['orders']];
}
