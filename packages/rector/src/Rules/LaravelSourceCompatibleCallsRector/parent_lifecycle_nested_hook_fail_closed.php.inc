<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class NestedHookTest extends TestCase
{
    protected function setUp(): void
    {
        $hook = function (): void { parent::setUp(); };
        $hook();
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): parent::setUp() lands on the converted Laratesto base, which provides no such method; the upstream assert rewrite skips parent receivers - migrate manually */
final class NestedHookTest extends TestCase
{
    protected function setUp(): void
    {
        $hook = function (): void { parent::setUp(); };
        $hook();
    }
}
