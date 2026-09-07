<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

final class NewTestResponseTest extends TestCase
{
    public function test_construction(): void
    {
        $response = new TestResponse(new Response('body'));

        $response->assertOk();
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class NewTestResponseTest extends TestCase
{
    public function test_construction(): void
    {
        $response = new \Laratesto\Testing\LaravelResponse(new Response('body'));

        $response->assertOk();
    }
}
