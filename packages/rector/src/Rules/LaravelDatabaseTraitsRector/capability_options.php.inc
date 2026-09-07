<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\DemoSeeder;
use Illuminate\Foundation\Testing\Attributes\Seed;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

#[Seed]
#[Seeder(DemoSeeder::class)]
final class RefreshOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;
    protected bool $dropTypes = true;
    protected array $connectionsToTransact = ['sqlite', 'secondary'];
}

final class TransactionOptionsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['sqlite', 'secondary'];
}

final class TruncationSelectionTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['sqlite', 'secondary'];
    protected array $tablesToTruncate = ['sqlite' => ['things'], 'secondary' => ['audit_entries']];
    protected array $exceptTables = ['sqlite' => ['audit_entries']];
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\DemoSeeder;
use Illuminate\Foundation\Testing\Attributes\Seed;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTransact changes the migration scope: the trait always runs migrate:fresh on the default connection, while the attribute would refresh the selected connections - migrate it manually */
#[Seed]
#[Seeder(DemoSeeder::class)]
final class RefreshOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;
    protected bool $dropTypes = true;
    protected array $connectionsToTransact = ['sqlite', 'secondary'];
}

#[\Laratesto\Attribute\DatabaseTransactions(connections: ['sqlite', 'secondary'])]
final class TransactionOptionsTest extends TestCase
{
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTruncate changes the migration and seeding scope: the trait only truncates the selected connections while its first migrate:fresh and every later db:seed run on the default connection, but the attribute would migrate and seed every selected connection - migrate it manually */
final class TruncationSelectionTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['sqlite', 'secondary'];
    protected array $tablesToTruncate = ['sqlite' => ['things'], 'secondary' => ['audit_entries']];
    protected array $exceptTables = ['sqlite' => ['audit_entries']];
}
