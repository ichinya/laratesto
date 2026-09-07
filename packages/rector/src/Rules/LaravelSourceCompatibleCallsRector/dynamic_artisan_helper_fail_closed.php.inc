<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class DynamicArtisanHelperFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $assertSuccessful = "run";
        $this->artisan("cache:clear")->{$assertSuccessful}();
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): dynamic Laravel helper method cannot be classified */
final class DynamicArtisanHelperFailClosedTest extends TestCase
{
    public function test_example(): void
    {
        $assertSuccessful = "run";
        $this->artisan("cache:clear")->{$assertSuccessful}();
    }
}
