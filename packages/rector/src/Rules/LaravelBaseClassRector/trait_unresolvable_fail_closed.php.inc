<?php

declare(strict_types=1);

namespace Tests\Feature;

use Acme\Nowhere\MissingTrait;
use Illuminate\Foundation\Testing\TestCase;

final class MissingTraitTest extends TestCase
{
    use MissingTrait;

    public function test_ok(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Acme\Nowhere\MissingTrait;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): used trait Acme\Nowhere\MissingTrait cannot be resolved, so a lifecycle or bootstrap override it provides cannot be ruled out */
/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): used trait Acme\Nowhere\MissingTrait could not be resolved, so a hidden database strategy cannot be ruled out - migrate it manually */
final class MissingTraitTest extends TestCase
{
    use MissingTrait;

    public function test_ok(): void {}
}
