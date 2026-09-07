<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class SubResponse extends TestResponse
{
}

final class SubclassReceiverTest extends LaravelTestCase
{
    private SubResponse $cached;

    private function fetch(): SubResponse
    {
        return $this->get('/users');
    }

    public function test_subclass_receivers(): void
    {
        $this->cached = $this->get('/users');
        $this->cached->assertStatus(200);
        $this->fetch()->assertOk();
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class SubResponse extends TestResponse
{
}

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): Tests\Feature\SubResponse extends TestResponse, but the Laratesto runtime never produces TestResponse subclasses; migrate the receiver manually */
final class SubclassReceiverTest extends LaravelTestCase
{
    private SubResponse $cached;

    private function fetch(): SubResponse
    {
        return $this->get('/users');
    }

    public function test_subclass_receivers(): void
    {
        $this->cached = $this->get('/users');
        $this->cached->assertStatus(200);
        $this->fetch()->assertOk();
    }
}
