<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class DynamicSelfStaticFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $assertion = "assertTrue";
        self::{$assertion}(true);
        static::{$assertion}(true);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): dynamic self/static method cannot be classified */
final class DynamicSelfStaticFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $assertion = "assertTrue";
        self::{$assertion}(true);
        static::{$assertion}(true);
    }
}
