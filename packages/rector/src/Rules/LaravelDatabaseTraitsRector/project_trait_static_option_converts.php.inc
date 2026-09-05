<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait StaticSeedOptions
{
    protected static bool $seed = true;
}

final class StaticSeedTest extends TestCase
{
    use RefreshDatabase;
    use StaticSeedOptions;

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

trait StaticSeedOptions
{
    protected static bool $seed = true;
}

#[\Laratesto\Attribute\RefreshDatabase]
final class StaticSeedTest extends TestCase
{
    use StaticSeedOptions;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
