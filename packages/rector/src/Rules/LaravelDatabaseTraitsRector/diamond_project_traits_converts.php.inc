<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait SharedSetup
{
    public function shared(): int
    {
        return 1;
    }
}

trait LeftSetup
{
    use SharedSetup;
}

trait RightSetup
{
    use SharedSetup;
}

final class DiamondTest extends TestCase
{
    use RefreshDatabase;
    use LeftSetup;
    use RightSetup;

    public function test_store(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait SharedSetup
{
    public function shared(): int
    {
        return 1;
    }
}

trait LeftSetup
{
    use SharedSetup;
}

trait RightSetup
{
    use SharedSetup;
}

#[\Laratesto\Attribute\RefreshDatabase]
final class DiamondTest extends TestCase
{
    use LeftSetup;
    use RightSetup;

    public function test_store(): void {}
}
