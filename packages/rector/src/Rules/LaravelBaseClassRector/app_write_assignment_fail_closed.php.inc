<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class AppWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app = app();
    }

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): $this->app is used in a write context (assignment/isset/unset/reference) that cannot become an app() method call */
final class AppWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app = app();
    }

    public function test_ok(): void {}
}
