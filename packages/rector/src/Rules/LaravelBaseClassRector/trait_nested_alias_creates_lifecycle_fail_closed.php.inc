<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait SilentWorker
{
    protected function silent(): void
    {
        parent::tearDown();
    }
}

trait AliasProvider
{
    use SilentWorker {
        silent as tearDown;
    }
}

final class CreatedAliasTest extends TestCase
{
    use AliasProvider;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait SilentWorker
{
    protected function silent(): void
    {
        parent::tearDown();
    }
}

trait AliasProvider
{
    use SilentWorker {
        silent as tearDown;
    }
}

/* laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): trait Tests\Feature\AliasProvider adapts a used trait method to tearDown(), which the conversion cannot rename safely */
final class CreatedAliasTest extends TestCase
{
    use AliasProvider;

    public function test_ok(): void {}
}
