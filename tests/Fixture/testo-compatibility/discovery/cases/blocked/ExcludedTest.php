<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Discovery;

use Testo\Assert;
use Testo\Test;
use function Laratesto\Tests\Fixture\Compatibility\event;

final class ExcludedTest
{
    #[Test]
    public function explicitFileInclusionWinsOverDirectoryExclusion(): void
    {
        event('explicit file');
        Assert::true(true);
    }
}
