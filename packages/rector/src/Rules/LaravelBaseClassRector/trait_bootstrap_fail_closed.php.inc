<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;

trait CreatesCustomApplication
{
    protected function createApplication(): Application
    {
        return require __DIR__ . '/bootstrap/app.php';
    }
}

final class TraitBootstrapTest extends TestCase
{
    use CreatesCustomApplication;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;

trait CreatesCustomApplication
{
    protected function createApplication(): Application
    {
        return require __DIR__ . '/bootstrap/app.php';
    }
}

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): trait Tests\Feature\CreatesCustomApplication provides custom bootstrap method createApplication() which is not called by the Laratesto target */
final class TraitBootstrapTest extends TestCase
{
    use CreatesCustomApplication;

    public function test_ok(): void {}
}
