<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class SeededTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

#[\Laratesto\Attribute\RefreshDatabase(seed: true)]
final class SeededTest extends TestCase
{
}
