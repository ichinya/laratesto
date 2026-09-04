<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class StaticEmptinessAssertsTest extends TestCase
{
    public function test_provable_subjects(): void
    {
        self::assertEmpty([], 'nothing in there');
        self::assertNotEmpty(['acme']);
    }

    public function test_unprovable_subject_fails_closed(): void
    {
        $subject = $this->acmeRows();

        self::assertNotEmpty($subject);
    }

    private function acmeRows(): array
    {
        return [];
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

/* laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): assertNotEmpty() argument 1 is not a statically supported array */
final class StaticEmptinessAssertsTest extends TestCase
{
    public function test_provable_subjects(): void
    {
        self::assertEmpty([], 'nothing in there');
        self::assertNotEmpty(['acme']);
    }

    public function test_unprovable_subject_fails_closed(): void
    {
        $subject = $this->acmeRows();

        self::assertNotEmpty($subject);
    }

    private function acmeRows(): array
    {
        return [];
    }
}
