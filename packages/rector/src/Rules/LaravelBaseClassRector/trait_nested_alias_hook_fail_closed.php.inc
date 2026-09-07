<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Inner { protected function helper(): void {} }
trait Provider { use Inner { helper as tearDownProvider; } }

final class TraitDependencyTest extends \Illuminate\Foundation\Testing\TestCase
{
    use Provider;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Inner { protected function helper(): void {} }
trait Provider { use Inner { helper as tearDownProvider; } }

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): Laravel trait hook tearDownProvider() is not called by the Laratesto target - migrate the hook manually before converting its consumer */
final class TraitDependencyTest extends \Illuminate\Foundation\Testing\TestCase
{
    use Provider;

    public function test_ok(): void {}
}
