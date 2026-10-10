<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Coverage;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Codecov\CoversNothing;
use Testo\Fiber\RunInFiber;
use Testo\Test;
use function Laratesto\Tests\Fixture\Compatibility\event;

require_once dirname(__DIR__).'/src/Covered.php';
require_once dirname(__DIR__).'/src/Excluded.php';

#[Covers(Covered::class)]
abstract class CoveredParent
{
    #[Test]
    public function inheritedCoverage(): void { event('body'); Assert::same(42, Covered::value()); }
}
final class CoveredChild extends CoveredParent
{
    #[Test, CoversNothing]
    public function excludedButExecuted(): void { event('body'); Assert::same(99, Excluded::value()); }

    #[Test, Covers(Covered::class), RunInFiber]
    public function soloLineCoverage(): void
    {
        event('body');
        Assert::same(42, Covered::value());
        \Fiber::suspend();
        Assert::same(42, Covered::value());
    }

    #[Test, Covers(Covered::class), CoversNothing]
    public function sameLevelConflict(): void { event('unexpected body'); }
}
