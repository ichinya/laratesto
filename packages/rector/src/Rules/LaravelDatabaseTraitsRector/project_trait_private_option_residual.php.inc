<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait PrivateSeedOptions
{
    private bool $seed = true;
}

final class PrivateSeedTest extends TestCase
{
    use RefreshDatabase;
    use PrivateSeedOptions;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait PrivateSeedOptions
{
    private bool $seed = true;
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $seed on trait Tests\Feature\PrivateSeedOptions requires manual migration */
final class PrivateSeedTest extends TestCase
{
    use RefreshDatabase;
    use PrivateSeedOptions;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
