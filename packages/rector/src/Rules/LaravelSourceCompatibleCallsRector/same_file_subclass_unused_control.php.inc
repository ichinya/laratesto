<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class PlainSwapTest extends LaravelTestCase
{
    private TestResponse $pending;

    public function response(): TestResponse
    {
        return $this->get('/safe');
    }

    public function hold(TestResponse $response): void
    {
        $this->pending = $response;
    }
}

final class UnusedResponse extends TestResponse
{
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Laratesto\Testing\LaravelTestCase;

final class PlainSwapTest extends LaravelTestCase
{
    private \Laratesto\Testing\LaravelResponse $pending;

    public function response(): \Laratesto\Testing\LaravelResponse
    {
        return $this->get('/safe');
    }

    public function hold(\Laratesto\Testing\LaravelResponse $response): void
    {
        $this->pending = $response;
    }
}

final class UnusedResponse extends TestResponse
{
}
