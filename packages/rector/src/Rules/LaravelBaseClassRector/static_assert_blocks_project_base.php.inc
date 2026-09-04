<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    public function seedAcme(): void
    {
        self::assertStringContainsString('acme', $this->acmeLabel());
    }

    public function acmeLabel(): string
    {
        return 'acme';
    }
}

namespace Tests\Feature;

use Tests\TestCase;

final class SafeDescendantTest extends TestCase
{
    public function test_safe_descendant(): void
    {
        $this->travel(5);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): self::assertStringContainsString() is outside the supported helper matrix */
abstract class TestCase extends FoundationTestCase
{
    public function seedAcme(): void
    {
        self::assertStringContainsString('acme', $this->acmeLabel());
    }

    public function acmeLabel(): string
    {
        return 'acme';
    }
}

namespace Tests\Feature;

use Tests\TestCase;

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): project base Tests\TestCase carries unsupported constructs: a blocking HTTP_UNSUPPORTED_SIGNATURE residual marker is already present; self::assertStringContainsString() is outside the supported helper matrix */
final class SafeDescendantTest extends TestCase
{
    public function test_safe_descendant(): void
    {
        $this->travel(5);
    }
}
