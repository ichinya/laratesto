<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait ProjectOptions
{
    protected bool $seed = true;
}

final class SeededFromTraitTest extends TestCase
{
    use RefreshDatabase;
    use ProjectOptions;

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

trait ProjectOptions
{
    protected bool $seed = true;
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $seed on trait Tests\Feature\ProjectOptions requires manual migration */
final class SeededFromTraitTest extends TestCase
{
    use RefreshDatabase;
    use ProjectOptions;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
