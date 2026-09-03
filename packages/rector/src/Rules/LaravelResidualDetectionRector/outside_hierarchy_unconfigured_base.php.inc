<?php

declare(strict_types=1);

namespace Acme\Testing;

abstract class AcmeCase extends \Tests\TestCase
{
}

namespace Tests\Feature;

use Acme\Testing\AcmeCase;

final class SignupTest extends AcmeCase
{
    protected ?object $app = null;

    public function visit(string $uri): object
    {
        return $this->app !== null ? $this->get($uri) : throw new \RuntimeException('no app');
    }
}
-----
<?php

declare(strict_types=1);

namespace Acme\Testing;

abstract class AcmeCase extends \Tests\TestCase
{
}

namespace Tests\Feature;

use Acme\Testing\AcmeCase;

/* laratesto-residual(code=LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual): Laravel constructs outside a convertible hierarchy ($this->app) — project base(s) Acme\Testing\AcmeCase resolvable but missing from base_classes: add CLI option(s) --base-class=Acme/Testing/AcmeCase (or add these names to the base_classes config), remove this residual marker, then re-run to convert the whole chain */
final class SignupTest extends AcmeCase
{
    protected ?object $app = null;

    public function visit(string $uri): object
    {
        return $this->app !== null ? $this->get($uri) : throw new \RuntimeException('no app');
    }
}
