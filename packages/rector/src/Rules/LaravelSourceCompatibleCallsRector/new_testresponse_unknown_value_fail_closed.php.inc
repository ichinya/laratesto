<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;

final class UnknownConstructionTest extends TestCase
{
    public function response($value): TestResponse
    {
        return new TestResponse($value);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): new TestResponse() requires exactly one proven Symfony Response argument for conversion; preserve the Laravel response type and migrate manually */
final class UnknownConstructionTest extends TestCase
{
    public function response($value): TestResponse
    {
        return new TestResponse($value);
    }
}
