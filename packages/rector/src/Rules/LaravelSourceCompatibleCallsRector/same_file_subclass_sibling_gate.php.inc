<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class SafeSiblingTest extends LaravelTestCase
{
    private TestResponse $pending;

    public function response(): TestResponse
    {
        return $this->get('/safe');
    }
}

final class SubclassReceiverTest extends LaravelTestCase
{
    private SubResponse $cached;

    private function fetch(): SubResponse
    {
        return $this->get('/users');
    }
}

final class SubResponse extends TestResponse
{
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): the file-wide TestResponse to Laratesto\Testing\LaravelResponse swap was blocked by sibling class Tests\Feature\SubclassReceiverTest; migrate TestResponse types to Laratesto\Testing\LaravelResponse manually */
final class SafeSiblingTest extends LaravelTestCase
{
    private TestResponse $pending;

    public function response(): TestResponse
    {
        return $this->get('/safe');
    }
}

/* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual): Tests\Feature\SubResponse extends TestResponse, but the Laratesto runtime never produces TestResponse subclasses; migrate the receiver manually */
final class SubclassReceiverTest extends LaravelTestCase
{
    private SubResponse $cached;

    private function fetch(): SubResponse
    {
        return $this->get('/users');
    }
}

final class SubResponse extends TestResponse
{
}
