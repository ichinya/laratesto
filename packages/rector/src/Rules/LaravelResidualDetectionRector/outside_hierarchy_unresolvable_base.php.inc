<?php

declare(strict_types=1);

namespace Tests\Feature;

final class OrphanTest extends \Ghost\UnknownCase
{
    protected ?object $app = null;

    public function visit(): void
    {
        if ($this->app === null) {
            throw new \RuntimeException('no app');
        }
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

/* laratesto-residual(code=LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual): Laravel constructs outside a convertible hierarchy ($this->app) — base class does not resolve; migrate manually */
final class OrphanTest extends \Ghost\UnknownCase
{
    protected ?object $app = null;

    public function visit(): void
    {
        if ($this->app === null) {
            throw new \RuntimeException('no app');
        }
    }
}
