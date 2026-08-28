<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

abstract class CustomScenario
{
    use RefreshDatabase;

    protected ?object $app = null;

    public function visit(string $uri): TestResponse
    {
        return $this->app !== null ? $this->get($uri) : throw new \RuntimeException('no app');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/* laratesto-residual(code=LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual): Laravel constructs outside a convertible hierarchy (RefreshDatabase trait, TestResponse, $this->app) — base class does not resolve; migrate manually */
abstract class CustomScenario
{
    use RefreshDatabase;

    protected ?object $app = null;

    public function visit(string $uri): TestResponse
    {
        return $this->app !== null ? $this->get($uri) : throw new \RuntimeException('no app');
    }
}
