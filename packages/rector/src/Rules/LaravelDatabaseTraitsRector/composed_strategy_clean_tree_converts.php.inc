<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait PlainComposition
{
    public function composedHelper(): bool
    {
        return true;
    }
}

final class CleanComposedTest extends TestCase
{
    use RefreshDatabase;
    use PlainComposition;

    public function test_store(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait PlainComposition
{
    public function composedHelper(): bool
    {
        return true;
    }
}

#[\Laratesto\Attribute\RefreshDatabase]
final class CleanComposedTest extends TestCase
{
    use PlainComposition;

    public function test_store(): void {}
}
