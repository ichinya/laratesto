<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait BootsUpper
{
    protected function SETUP(): void
    {
        parent::setUp();
    }
}

final class UpperTraitBootTest extends TestCase
{
    use BootsUpper;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait BootsUpper
{
    protected function SETUP(): void
    {
        parent::setUp();
    }
}

/* laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): trait Tests\Feature\BootsUpper provides SETUP() which the conversion cannot rename safely */
final class UpperTraitBootTest extends TestCase
{
    use BootsUpper;

    public function test_ok(): void {}
}
