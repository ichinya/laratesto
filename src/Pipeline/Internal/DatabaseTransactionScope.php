<?php

declare(strict_types=1);

namespace Laratesto\Pipeline\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/** @internal Begins a multi-connection test transaction and unwinds exactly the depth it owns. */
final class DatabaseTransactionScope
{
    /** @var list<array{name: non-empty-string, connection: Connection, floor: int}> */
    private array $managed = [];

    private bool $closed = false;

    /**
     * @param list<non-empty-string> $connections
     */
    public function __construct(
        private readonly Application $application,
        private readonly array $connections,
        private readonly bool $guardsRefreshState = false,
    ) {}

    public function begin(): void
    {
        $database = $this->application['db'];
        $manager = new DatabaseTransactionsManager($this->connections);
        $this->application->instance('db.transactions', $manager);

        try {
            foreach ($this->connections as $name) {
                /** @var Connection $connection */
                $connection = $database->connection($name);
                // The transaction depth this scope owns: close() may only unwind
                // what it began, so an outer scope's transaction survives.
                $floor = $connection->transactionLevel();
                $connection->setTransactionManager($manager);
                $this->managed[] = ['name' => $name, 'connection' => $connection, 'floor' => $floor];

                $dispatcher = $connection->getEventDispatcher();
                $connection->unsetEventDispatcher();

                try {
                    $connection->beginTransaction();
                } finally {
                    $connection->setEventDispatcher($dispatcher);
                }
            }
        } catch (\Throwable $failure) {
            $this->closeQuietly();
            throw $failure;
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $firstFailure = null;

        foreach (array_reverse($this->managed) as $owned) {
            $connection = $owned['connection'];
            $dispatcher = $connection->getEventDispatcher();
            $connection->unsetEventDispatcher();

            try {
                $pdo = $connection->getPdo();

                // Mirror Laravel's null-PDO guard: a disconnected connection
                // has nothing to roll back and never invalidates the schema.
                // The transaction it carried survives on the cached in-memory
                // PDO, so unwind it there to keep the cache reusable.
                if ($pdo === null) {
                    DatabaseRuntime::rollbackCachedTransaction($this->application, $owned['name']);
                    continue;
                }

                if ($this->guardsRefreshState && ! $pdo->inTransaction()) {
                    RefreshDatabaseState::$migrated = false;
                }

                // Unwind only the depth this scope owns so stacked scopes never
                // corrupt the transaction of an outer scope.
                if ($connection->transactionLevel() > $owned['floor']) {
                    $connection->rollBack($owned['floor']);
                }
            } catch (\Throwable $failure) {
                $firstFailure ??= $failure;
                $this->guardsRefreshState and RefreshDatabaseState::$migrated = false;
            } finally {
                $connection->unsetTransactionManager();
                $connection->setEventDispatcher($dispatcher);
            }
        }

        $this->application->forgetInstance('db.transactions');
        $this->application->offsetUnset('db.transactions');

        if ($firstFailure instanceof \Throwable) {
            throw $firstFailure;
        }
    }

    public function closeQuietly(): void
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // The original test/pipeline/begin failure remains authoritative.
        }
    }
}
