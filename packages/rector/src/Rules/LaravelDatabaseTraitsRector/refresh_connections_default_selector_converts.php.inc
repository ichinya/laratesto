<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class DefaultSelectorTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [null];
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

#[\Laratesto\Attribute\RefreshDatabase]
final class DefaultSelectorTest extends TestCase
{
}
