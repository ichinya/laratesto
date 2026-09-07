<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait SameSeedOptions
{
    protected bool $seed = true;
}

final class SamePropertyTest extends TestCase
{
    use RefreshDatabase;
    use SameSeedOptions;

    protected bool $seed = true;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait SameSeedOptions
{
    protected bool $seed = true;
}

#[\Laratesto\Attribute\RefreshDatabase(seed: true)]
final class SamePropertyTest extends TestCase
{
    use SameSeedOptions;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
