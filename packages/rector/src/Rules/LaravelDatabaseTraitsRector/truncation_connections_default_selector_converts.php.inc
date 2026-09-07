<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;

final class DefaultSelectorTest extends TestCase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [null];

    protected bool $seed = true;

    protected array $tablesToTruncate = ['orders'];
}

final class PlainControlTest extends TestCase
{
    use DatabaseTruncation;
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

#[\Laratesto\Attribute\DatabaseTruncation(seed: true, tables: ['orders'])]
final class DefaultSelectorTest extends TestCase
{
}

#[\Laratesto\Attribute\DatabaseTruncation]
final class PlainControlTest extends TestCase
{
}
