<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Provider
{
    protected function helper(): void { $case = $this; $case->assertTrue(true); }
}

final class TraitDependencyTest extends \Illuminate\Foundation\Testing\TestCase
{
    use Provider;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Provider
{
    protected function helper(): void { $case = $this; $case->assertTrue(true); }
}

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): trait Tests\Feature\Provider method helper() aliases or passes $this whose Laravel/PHPUnit dependencies cannot be proved safe - migrate the trait manually before converting its consumer */
final class TraitDependencyTest extends \Illuminate\Foundation\Testing\TestCase
{
    use Provider;

    public function test_ok(): void {}
}
