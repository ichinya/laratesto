<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class MissingRequestVerbsTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $this->putJson('/api/things/1', ['name' => 'A']);
        $this->patchJson('/api/things/2', ['name' => 'B']);
        $this->options('/api/things');
        $this->optionsJson('/api/things', ['a' => 1]);
        $this->head('/api/things');
        $this->json('PUT', '/api/things/3', ['name' => 'C']);
        $this->session(['cart' => ['sku' => 1]]);
        $this->seed();

        return $this->get('/api/things');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): $this->putJson() is outside the supported helper matrix; $this->patchJson() is outside the supported helper matrix; $this->options() is outside the supported helper matrix; $this->optionsJson() is outside the supported helper matrix; $this->head() is outside the supported helper matrix; $this->json() is outside the supported helper matrix; session() expects 0..0 common-path arguments; got 1; $this->seed() is outside the supported helper matrix */
final class MissingRequestVerbsTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        $this->putJson('/api/things/1', ['name' => 'A']);
        $this->patchJson('/api/things/2', ['name' => 'B']);
        $this->options('/api/things');
        $this->optionsJson('/api/things', ['a' => 1]);
        $this->head('/api/things');
        $this->json('PUT', '/api/things/3', ['name' => 'C']);
        $this->session(['cart' => ['sku' => 1]]);
        $this->seed();

        return $this->get('/api/things');
    }
}
