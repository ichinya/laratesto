<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;

final class FlatListTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [null];

    protected array $tablesToTruncate = ['orders'];
}

final class EmptyListTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [];
}

final class FlatExceptTest extends TestCase
{
    use DatabaseTruncation;

    protected array $exceptTables = ['audit_entries'];
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

#[\Laratesto\Attribute\DatabaseTruncation(tables: ['orders'])]
final class FlatListTest extends TestCase
{
}

#[\Laratesto\Attribute\DatabaseTruncation(tables: [])]
final class EmptyListTest extends TestCase
{
}

#[\Laratesto\Attribute\DatabaseTruncation(exceptTables: ['audit_entries'])]
final class FlatExceptTest extends TestCase
{
}
