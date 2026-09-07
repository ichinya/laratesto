<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class BillingTest extends TestCase
{
    use RefreshDatabase;
}

trait ReusableSetup
{
    use RefreshDatabase;
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

#[\Laratesto\Attribute\RefreshDatabase]
final class BillingTest extends TestCase
{
}

trait ReusableSetup
{
    use RefreshDatabase;
}
