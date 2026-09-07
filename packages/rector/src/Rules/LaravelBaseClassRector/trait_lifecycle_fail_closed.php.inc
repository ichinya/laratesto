<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait BootsPerTenant
{
    protected function setUp(): void
    {
        parent::setUp();
    }
}

final class TraitBootTest extends TestCase
{
    use BootsPerTenant;

    public function test_ok(): void
    {
        $this->app->make('cache');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait BootsPerTenant
{
    protected function setUp(): void
    {
        parent::setUp();
    }
}

/* laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): trait Tests\Feature\BootsPerTenant provides setUp() which the conversion cannot rename safely */
final class TraitBootTest extends TestCase
{
    use BootsPerTenant;

    public function test_ok(): void
    {
        $this->app->make('cache');
    }
}
