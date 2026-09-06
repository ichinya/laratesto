<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait DeepTearDown
{
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}

trait WrapsDeepTearDown
{
    use DeepTearDown;
}

final class NestedTraitTest extends TestCase
{
    use WrapsDeepTearDown;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait DeepTearDown
{
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}

trait WrapsDeepTearDown
{
    use DeepTearDown;
}

/* laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): trait Tests\Feature\DeepTearDown provides tearDown() which the conversion cannot rename safely */
final class NestedTraitTest extends TestCase
{
    use WrapsDeepTearDown;

    public function test_ok(): void {}
}
