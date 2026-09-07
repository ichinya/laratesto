<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

trait BaseSeedOptions
{
    protected bool $seed = true;
}

abstract class TestCase extends FoundationTestCase
{
    use BaseSeedOptions;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class TraitShadowTest extends \Tests\TestCase
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

trait BaseSeedOptions
{
    protected bool $seed = true;
}

abstract class TestCase extends FoundationTestCase
{
    use BaseSeedOptions;
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

#[\Laratesto\Attribute\RefreshDatabase(seed: false)]
final class TraitShadowTest extends \Tests\TestCase
{
    public function test_store(): void
    {
        $this->get('/store');
    }
}
