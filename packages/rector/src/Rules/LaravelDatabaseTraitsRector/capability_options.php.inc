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
use Illuminate\Foundation\Testing\TestCase;

#[\Laratesto\Attribute\RefreshDatabase(seed: true, seeder: DemoSeeder::class, dropViews: true, dropTypes: true, connections: ['sqlite', 'secondary'])]
final class RefreshOptionsTest extends TestCase
{
}

#[\Laratesto\Attribute\DatabaseTransactions(connections: ['sqlite', 'secondary'])]
final class TransactionOptionsTest extends TestCase
{
}

#[\Laratesto\Attribute\DatabaseTruncation(connections: ['sqlite', 'secondary'], tables: ['sqlite' => ['things'], 'secondary' => ['audit_entries']], exceptTables: ['sqlite' => ['audit_entries']])]
final class TruncationSelectionTest extends TestCase
{
}
