<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;

trait BootingHelper
{
    protected function boot(): Application
    {
        return require __DIR__ . '/bootstrap/app.php';
    }
}

trait BootstrapAliasProvider
{
    use BootingHelper {
        boot as createApplication;
    }
}

final class CreatedBootstrapAliasTest extends TestCase
{
    use BootstrapAliasProvider;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;

trait BootingHelper
{
    protected function boot(): Application
    {
        return require __DIR__ . '/bootstrap/app.php';
    }
}

trait BootstrapAliasProvider
{
    use BootingHelper {
        boot as createApplication;
    }
}

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): trait Tests\Feature\BootstrapAliasProvider adapts a used trait method to custom bootstrap method createApplication() which is not called by the Laratesto target */
final class CreatedBootstrapAliasTest extends TestCase
{
    use BootstrapAliasProvider;

    public function test_ok(): void {}
}
