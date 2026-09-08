<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class UnsupportedStaticAssertsTest extends TestCase
{
    public function test_haystack(): void
    {
        $haystack = 'acme label';

        self::assertStringContainsStringIgnoringCase('acme', $haystack);
        static::assertFileExists('/tmp/acme');
    }

    public function test_dynamic_call(): void
    {
        $method = 'assertTrue';

        self::$method();
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): self::assertStringContainsStringIgnoringCase() is outside the supported helper matrix; dynamic self/static method cannot be classified */
final class UnsupportedStaticAssertsTest extends TestCase
{
    public function test_haystack(): void
    {
        $haystack = 'acme label';

        self::assertStringContainsStringIgnoringCase('acme', $haystack);
        static::assertFileExists('/tmp/acme');
    }

    public function test_dynamic_call(): void
    {
        $method = 'assertTrue';

        self::$method();
    }
}
