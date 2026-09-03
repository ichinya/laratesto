<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class PlainFunctionCoexistenceTest extends LaravelTestCase
{
    public function response(): TestResponse
    {
        return $this->get('/users');
    }
}

function makeResponse(): TestResponse
{
    return new TestResponse(null);
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class PlainFunctionCoexistenceTest extends LaravelTestCase
{
    public function response(): \Laratesto\Testing\LaravelResponse
    {
        return $this->get('/users');
    }
}

function makeResponse(): TestResponse
{
    return new TestResponse(null);
}
