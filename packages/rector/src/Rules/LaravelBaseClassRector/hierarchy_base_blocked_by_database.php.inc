<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    use DatabaseTransactions;
    use RefreshDatabase;

    public function acmeHelper(): string
    {
        return 'acme';
    }
}

namespace Tests\Feature;

use Tests\TestCase;

final class SafeDescendantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_safe_descendant(): void
    {
        $this->travel(5);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): multiple Laravel database traits are not converted automatically */
abstract class TestCase extends FoundationTestCase
{
    use DatabaseTransactions;
    use RefreshDatabase;

    public function acmeHelper(): string
    {
        return 'acme';
    }
}

namespace Tests\Feature;

use Tests\TestCase;

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): project base Tests\TestCase carries unsupported constructs: a blocking DATABASE_UNSUPPORTED_CONFIGURATION residual marker is already present; multiple Laravel database traits are not converted automatically */
/* laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION, rule=Laratesto\Rector\Rules\LaravelDatabaseTraitsRector, severity=manual): inherited database strategy Illuminate\Foundation\Testing\RefreshDatabase from Tests\TestCase: ancestor Tests\TestCase also uses Illuminate\Foundation\Testing\RefreshDatabase but cannot be converted automatically: multiple Laravel database traits are not converted automatically */
final class SafeDescendantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_safe_descendant(): void
    {
        $this->travel(5);
    }
}
