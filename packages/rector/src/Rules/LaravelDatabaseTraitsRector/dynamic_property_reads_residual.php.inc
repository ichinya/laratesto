<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class LiteralDynamicReadTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DemoSeeder::class;

    public function currentSeeder(): string
    {
        return $this->{'seeder'};
    }
}

final class VariableDynamicReadTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function seedEnabled(string $option): bool
    {
        return $this->{$option} === true;
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $seeder is also used by class code */
final class LiteralDynamicReadTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DemoSeeder::class;

    public function currentSeeder(): string
    {
        return $this->{'seeder'};
    }
}

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $seed is also used by class code */
final class VariableDynamicReadTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function seedEnabled(string $option): bool
    {
        return $this->{$option} === true;
    }
}
