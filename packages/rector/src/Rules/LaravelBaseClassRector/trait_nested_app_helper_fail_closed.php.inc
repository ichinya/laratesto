<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Inner { protected function helper(): void { $this->app->make("config"); } }
trait Provider { use Inner; }

final class TraitDependencyTest extends \Illuminate\Foundation\Testing\TestCase
{
    use Provider;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

trait Inner { protected function helper(): void { $this->app->make("config"); } }
trait Provider { use Inner; }

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): trait Tests\Feature\Inner method helper() depends on inherited Laravel/PHPUnit property app - migrate the trait manually before converting its consumer */
final class TraitDependencyTest extends \Illuminate\Foundation\Testing\TestCase
{
    use Provider;

    public function test_ok(): void {}
}
