<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase as LaravelCase;

final class AliasedBaseTest extends LaravelCase
{
    public function test_aliased_base(): void
    {
        $this->get('/users')->assertStatus(200);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase as LaravelCase;

final class AliasedBaseTest extends \Laratesto\Testing\LaravelTestCase
{
    #[\Testo\Test]
    public function test_aliased_base(): void
    {
        $this->get('/users')->assertStatus(200);
    }
}
