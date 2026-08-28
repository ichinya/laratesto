<?php

declare(strict_types=1);

namespace Laratesto\Pipeline\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/** @internal Begins and exhaustively cleans a multi-connection test transaction. */
final class DatabaseTransactionScope
{
    /** @var list<Connection> */
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
                $connection->setTransactionManager($manager);
                $this->managed[] = $connection;

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

        foreach (array_reverse($this->managed) as $connection) {
            $dispatcher = $connection->getEventDispatcher();
            $connection->unsetEventDispatcher();

            try {
                if ($this->guardsRefreshState
                    && (! $connection->getPdo()->inTransaction() || $connection->transactionLevel() < 1)) {
                    RefreshDatabaseState::$migrated = false;
                }

                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack(0);
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
