<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

final class ResponseVariableTest extends TestCase
{
    public function test_construction(): void
    {
        $inner = new Response('body');
        $response = new TestResponse($inner);

        $response->assertOk();
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ResponseVariableTest extends TestCase
{
    public function test_construction(): void
    {
        $inner = new Response('body');
        $response = new \Laratesto\Testing\LaravelResponse($inner);

        $response->assertOk();
    }
}
