<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class DynamicThisHelperFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $get = "putJson";
        $this->{$get}("/");
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): dynamic Laravel helper method cannot be classified */
final class DynamicThisHelperFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $get = "putJson";
        $this->{$get}("/");
    }
}
