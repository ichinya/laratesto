<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;

final class ArchiveTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = ['archive'];

    protected array $tablesToTruncate = ['orders', 'invoices'];
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

#[\Laratesto\Attribute\DatabaseTruncation(connections: ['archive'], tables: ['orders', 'invoices'])]
final class ArchiveTest extends TestCase
{
}
