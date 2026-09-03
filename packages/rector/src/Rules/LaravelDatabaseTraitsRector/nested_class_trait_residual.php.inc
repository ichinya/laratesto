<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class NestedDatabaseTest extends TestCase
{
    public function makeRunner(): object
    {
        return new class
        {
            use RefreshDatabase;

            public function run(): bool
            {
                return true;
            }
        };
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database trait use inside a nested class is not converted automatically */
final class NestedDatabaseTest extends TestCase
{
    public function makeRunner(): object
    {
        return new class
        {
            use RefreshDatabase;

            public function run(): bool
            {
                return true;
            }
        };
    }
}
