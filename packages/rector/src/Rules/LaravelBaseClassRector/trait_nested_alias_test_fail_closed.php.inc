<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Inner { public function helper(): void {} }
trait Provider { use Inner { helper as testAliased; } }

final class TraitDependencyTest extends \Illuminate\Foundation\Testing\TestCase
{
    use Provider;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Inner { public function helper(): void {} }
trait Provider { use Inner { helper as testAliased; } }

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): trait Tests\Feature\Provider aliases a method to PHPUnit test name testAliased() - migrate the trait manually before converting its consumer */
final class TraitDependencyTest extends \Illuminate\Foundation\Testing\TestCase
{
    use Provider;

    public function test_ok(): void {}
}
