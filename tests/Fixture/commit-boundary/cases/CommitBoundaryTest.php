<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\CommitBoundary;

/** Intentionally has no database isolation attribute: the commit must be physical. */
final class CommitBoundaryTest extends \Laratesto\Testing\LaravelTestCase
{
    #[\Testo\Test]
    public function waitAtTheCommitBoundary(): void
    {
        $checkpointPath = getenv('LARATESTO_CRASH_CHECKPOINT');
        register_shutdown_function(static fn() => file_put_contents($checkpointPath.'.shutdown', 'graceful shutdown'));
        $phase = getenv('LARATESTO_CRASH_PHASE');
        if (!in_array($phase, ['before', 'after'], true)) {
            throw new \RuntimeException('Unknown commit boundary.');
        }
        $connection = $this->app()['db']->connection('sqlite');
        $initialLevel = $connection->transactionLevel();
        if ($initialLevel !== 0) {
            throw new \RuntimeException('Commit boundary fixture must not have an outer test transaction.');
        }
        $connection->beginTransaction();
        $connection->table('commit_probe')->insert(['id' => 19]);
        if ($phase === 'after') {
            $connection->commit();
        }
        file_put_contents($checkpointPath.'.tmp', json_encode([
            'phase' => $phase,
            'pid' => getmypid(),
            'initial_level' => $initialLevel,
            'transaction_level' => $connection->transactionLevel(),
        ], JSON_THROW_ON_ERROR));
        if (!rename($checkpointPath.'.tmp', $checkpointPath)) {
            throw new \RuntimeException('Unable to publish the commit checkpoint.');
        }
        while (true) {
            usleep(10_000);
        }
    }
}
