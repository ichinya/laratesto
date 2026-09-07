<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    protected bool $seed = true;

    public function observedSeed(string $option): bool
    {
        return (bool) ($this->{$option} ?? false);
    }
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

final class VariableDynamicInheritedReadTest extends \Tests\TestCase
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

    public function observedSeed(string $option): bool
    {
        return (bool) ($this->{$option} ?? false);
    }
}

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): database option $seed may be read dynamically by project code in Tests\TestCase and requires manual migration */
final class VariableDynamicInheritedReadTest extends \Tests\TestCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
