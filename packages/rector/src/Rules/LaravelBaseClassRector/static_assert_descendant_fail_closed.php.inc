<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    public function seedAcme(): void
    {
        self::assertSame('acme', $this->acmeLabel());
    }

    public function acmeLabel(): string
    {
        return 'acme';
    }
}

namespace Tests\Feature;

use Tests\TestCase;

final class DescendantStaticAssertsTest extends TestCase
{
    public function test_descendant(): void
    {
        self::assertStringContainsStringIgnoringCase('acme', 'acme label');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends \Laratesto\Testing\LaravelTestCase
{
    public function seedAcme(): void
    {
        self::assertSame('acme', $this->acmeLabel());
    }

    public function acmeLabel(): string
    {
        return 'acme';
    }
}

namespace Tests\Feature;

use Tests\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): self::assertStringContainsStringIgnoringCase() is outside the supported helper matrix */
final class DescendantStaticAssertsTest extends TestCase
{
    public function test_descendant(): void
    {
        self::assertStringContainsStringIgnoringCase('acme', 'acme label');
    }
}
