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

    public function test_dynamic_subject_is_supported(): void
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

final class StaticEmptinessAssertsTest extends \Laratesto\Testing\LaravelTestCase
{
    #[\Testo\Test]
    public function test_provable_subjects(): void
    {
        self::assertEmpty([], 'nothing in there');
        self::assertNotEmpty(['acme']);
    }

    #[\Testo\Test]
    public function test_dynamic_subject_is_supported(): void
    {
        $subject = $this->acmeRows();

        self::assertNotEmpty($subject);
    }

    private function acmeRows(): array
    {
        return [];
    }
}
