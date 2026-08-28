<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;

final class UsersListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('router');
    }

    public function response(): TestResponse
    {
        return $this->get('/users');
    }

    public function test_users_list_renders(): void
    {
        $response = $this->get('/users');

        $response->assertStatus(200);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;

final class UsersListTest extends \Laratesto\Testing\LaravelTestCase
{
    use RefreshDatabase;

    protected function setUpLaravel(): void
    {
        $this->make('router');
    }

    public function response(): TestResponse
    {
        return $this->get('/users');
    }

    #[\Testo\Test]
    public function test_users_list_renders(): void
    {
        $response = $this->get('/users');

        $response->assertStatus(200);
    }
}
