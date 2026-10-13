<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Tests\Support\CommitBoundaryRunner;
use Testo\Assert;
use Testo\Test;

final class CommitBoundaryCompatibilityTest
{
    #[Test]
    public function forcedCrashBeforeCommitDoesNotPublishTheWrite(): void
    {
        $this->verifyBoundary('before', expectedRows: 0, expectedLevel: 1);
    }

    #[Test]
    public function forcedCrashAfterCommitKeepsTheDurableWrite(): void
    {
        $this->verifyBoundary('after', expectedRows: 1, expectedLevel: 0);
    }

    private function verifyBoundary(string $phase, int $expectedRows, int $expectedLevel): void
    {
        $run = CommitBoundaryRunner::run($phase);
        Assert::same($phase, $run['checkpoint']['phase']);
        Assert::true($run['checkpoint']['pid'] > 0);
        Assert::same(0, $run['checkpoint']['initial_level']);
        Assert::same($expectedLevel, $run['checkpoint']['transaction_level']);
        Assert::notSame(0, $run['exit']);
        Assert::false($run['shutdownRan'], 'The child must be killed without PHP shutdown cleanup.');
        Assert::same($expectedRows, $run['visibleBeforeKill']);
        Assert::same($expectedRows, $run['durableAfterKill']);
        Assert::true($run['writableAfterKill'], 'An independent writer must acquire the database after the crash.');
    }
}
