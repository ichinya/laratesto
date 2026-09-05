<?php

declare(strict_types=1);

namespace Acme\Support;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

abstract class AcmeLazyCase
{
    use LazilyRefreshDatabase;
}
-----
<?php

declare(strict_types=1);

namespace Acme\Support;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

/* laratesto-residual(code=LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual): Laravel constructs outside a convertible hierarchy (LazilyRefreshDatabase trait) — base class does not resolve; migrate manually */
abstract class AcmeLazyCase
{
    use LazilyRefreshDatabase;
}
