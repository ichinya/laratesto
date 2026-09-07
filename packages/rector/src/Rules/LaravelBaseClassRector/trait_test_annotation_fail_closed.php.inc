<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Provider
{
    /** @test */
    public function fromTrait(): void {}
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
    /** @test */
    public function fromTrait(): void {}
}

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): trait Tests\Feature\Provider method fromTrait() uses PHPUnit test discovery that is not rewritten in traits - migrate the trait manually before converting its consumer */
final class TraitDependencyTest extends \Illuminate\Foundation\Testing\TestCase
{
    use Provider;

    public function test_ok(): void {}
}
