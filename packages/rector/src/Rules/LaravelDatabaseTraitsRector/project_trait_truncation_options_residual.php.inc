<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;

trait TruncationOptions
{
    protected array $connectionsToTruncate = ['archive'];

    protected array $tablesToTruncate = ['orders', 'invoices'];
}

final class ArchiveFromTraitTest extends TestCase
{
    use DatabaseTruncation;
    use TruncationOptions;
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;

trait TruncationOptions
{
    protected array $connectionsToTruncate = ['archive'];

    protected array $tablesToTruncate = ['orders', 'invoices'];
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $connectionsToTruncate on trait Tests\Feature\TruncationOptions requires manual migration */
final class ArchiveFromTraitTest extends TestCase
{
    use DatabaseTruncation;
    use TruncationOptions;
}
