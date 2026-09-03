<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

final class AssertEmptyNonArrayTest extends LaravelTestCase
{
    public function test_emptiness_on_non_array(): void
    {
        $value = 'x';

        $this->assertEmpty($value);
        $this->assertNotEmpty($value);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): assertEmpty() argument 1 is not a statically supported array; assertNotEmpty() argument 1 is not a statically supported array */
final class AssertEmptyNonArrayTest extends LaravelTestCase
{
    public function test_emptiness_on_non_array(): void
    {
        $value = 'x';

        $this->assertEmpty($value);
        $this->assertNotEmpty($value);
    }
}
