<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    protected bool $seed = true;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class ShadowedSeedTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    protected bool $seed = true;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

#[\Laratesto\Attribute\RefreshDatabase(seed: false)]
final class ShadowedSeedTest extends \Tests\TestCase
{
    public function test_store(): void
    {
        $this->get('/store');
    }
}
