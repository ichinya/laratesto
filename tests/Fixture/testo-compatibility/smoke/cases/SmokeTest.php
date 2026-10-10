<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility;

use Testo\Assert;
use Testo\Test;

final class SmokeTest
{
    #[Test]
    public function passes(): void { Assert::true(true); }

    #[Test]
    public function fails(): void { Assert::true(false, 'compatibility negative control'); }
}
