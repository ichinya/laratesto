<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class AdaptedDatabaseTraitTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshForTest;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database trait adaptations are not converted automatically */
final class AdaptedDatabaseTraitTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshForTest;
    }
}
