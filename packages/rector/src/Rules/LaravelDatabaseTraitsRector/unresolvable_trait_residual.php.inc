<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Vendor\External\NotInstallableSetup;

final class ExternalTraitTest extends TestCase
{
    use RefreshDatabase;
    use NotInstallableSetup;

    public function test_store(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Vendor\External\NotInstallableSetup;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): used trait Vendor\External\NotInstallableSetup could not be resolved, so its database options require manual migration */
final class ExternalTraitTest extends TestCase
{
    use RefreshDatabase;
    use NotInstallableSetup;

    public function test_store(): void {}
}
