<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class AssertJsonFluentClosureTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/api/users');

        $response->assertJson(fn (AssertableJson $json) => $json->where('data.id', 1));

        return $response;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): assertJson() argument 1 is not a statically supported array-or-closure */
final class AssertJsonFluentClosureTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/api/users');

        $response->assertJson(fn (AssertableJson $json) => $json->where('data.id', 1));

        return $response;
    }
}
