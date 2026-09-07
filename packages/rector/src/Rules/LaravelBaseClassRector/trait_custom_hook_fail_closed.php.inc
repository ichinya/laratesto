<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Provider { protected function setUpProvider(): void {} }

final class TraitDependencyTest extends \Illuminate\Foundation\Testing\TestCase
{
    use Provider;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Provider { protected function setUpProvider(): void {} }

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): Laravel trait hook setUpProvider() is not called by the Laratesto target - migrate the hook manually before converting its consumer */
final class TraitDependencyTest extends \Illuminate\Foundation\Testing\TestCase
{
    use Provider;

    public function test_ok(): void {}
}
