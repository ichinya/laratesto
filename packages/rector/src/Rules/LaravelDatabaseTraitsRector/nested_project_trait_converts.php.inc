<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait RunnerOptions
{
    protected bool $seed = true;
}

final class OuterTest extends TestCase
{
    use RefreshDatabase;

    public function test_runner(): void
    {
        $object = new class
        {
            use RunnerOptions;

            public function run(): bool
            {
                return true;
            }
        };

        $this->assertTrue($object->run());
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait RunnerOptions
{
    protected bool $seed = true;
}

#[\Laratesto\Attribute\RefreshDatabase]
final class OuterTest extends TestCase
{
    public function test_runner(): void
    {
        $object = new class
        {
            use RunnerOptions;

            public function run(): bool
            {
                return true;
            }
        };

        $this->assertTrue($object->run());
    }
}
