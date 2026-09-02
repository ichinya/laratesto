<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class MultiPropertyOptionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true, $keepMe = false;

    public function test_multi_property(): void
    {
        self::assertFalse($this->keepMe);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

#[\Laratesto\Attribute\RefreshDatabase(seed: true)]
final class MultiPropertyOptionTest extends TestCase
{
    protected bool $keepMe = false;

    public function test_multi_property(): void
    {
        self::assertFalse($this->keepMe);
    }
}
