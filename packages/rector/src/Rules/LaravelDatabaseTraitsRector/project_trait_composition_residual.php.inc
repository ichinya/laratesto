<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait DeeperOptions
{
    protected bool $dropTypes = true;
}

trait CompositionOptions
{
    use DeeperOptions;
}

final class ComposedTest extends TestCase
{
    use RefreshDatabase;
    use CompositionOptions;

    public function test_store(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait DeeperOptions
{
    protected bool $dropTypes = true;
}

trait CompositionOptions
{
    use DeeperOptions;
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $dropTypes on trait Tests\Feature\DeeperOptions requires manual migration */
final class ComposedTest extends TestCase
{
    use RefreshDatabase;
    use CompositionOptions;

    public function test_store(): void {}
}
