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
    /** @var list<array{name: non-empty-string, connection: Connection, floor: int, manager: object|null}> */
    private array $managed = [];

    private bool $closed = false;

    private bool $installed = false;

    /** Whether the container already bound db.transactions before begin() took it over. */
    private bool $hadPriorBinding = false;

    /** The stored shared instance captured before begin() replaced it, handed back verbatim at close(). */
    private ?object $priorInstance = null;

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
        $this->snapshotPriorManager();

        $manager = new DatabaseTransactionsManager($this->connections);
        $this->application->instance('db.transactions', $manager);
        $this->installed = true;

        try {
            foreach ($this->connections as $name) {
                /** @var Connection $connection */
                $connection = $database->connection($name);
                // The transaction depth this scope owns: close() may only unwind
                // what it began, so an outer scope's transaction survives.
                $floor = $connection->transactionLevel();
                // The manager embedded in the connection belongs to an outer
                // scope or the application itself: close() must hand it back.
                $priorManager = self::connectionTransactionManager($connection);
                $connection->setTransactionManager($manager);
                $this->managed[] = [
                    'name' => $name,
                    'connection' => $connection,
                    'floor' => $floor,
                    'manager' => $priorManager,
                ];

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
                // Hand the connection back exactly what begin() replaced: an
                // outer scope's manager or the application's own, so afterCommit
                // keeps working after a nested scope closes — success or failure.
                if ($owned['manager'] === null) {
                    $connection->unsetTransactionManager();
                } else {
                    $connection->setTransactionManager($owned['manager']);
                }

                $connection->setEventDispatcher($dispatcher);
            }
        }

        $this->restorePriorManager();

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

    /**
     * Record, without mutating anything, the db.transactions state the
     * container holds before this scope replaces it, so close() restores
     * exactly that instead of destroying a binding owned by an outer scope
     * or the application itself.
     */
    private function snapshotPriorManager(): void
    {
        $this->hadPriorBinding = $this->application->bound('db.transactions');

        if (! $this->hadPriorBinding) {
            return;
        }

        // Only a stored shared instance — a direct instance() or an already
        // resolved singleton — has a value worth handing back; make() is a
        // pure read for it. A shared-but-unresolved singleton stays lazy and
        // a non-shared factory keeps producing, even when it happens to
        // return a stable object: re-instancing either would change their
        // resolution semantics.
        if ($this->application->isShared('db.transactions') && $this->application->resolved('db.transactions')) {
            $this->priorInstance = $this->application->make('db.transactions');
        }
    }

    private function restorePriorManager(): void
    {
        // close() without a begin() owns no container state: never unbind
        // a manager this scope did not install.
        if (! $this->installed) {
            return;
        }

        $this->application->forgetInstance('db.transactions');

        if (! $this->hadPriorBinding) {
            // The scope installed the manager into an unbound container:
            // remove every trace of it, as no earlier state exists.
            $this->application->offsetUnset('db.transactions');

            return;
        }

        if ($this->priorInstance !== null) {
            // A nested scope hands the outer scope's manager back; an
            // application-owned instance likewise survives the scope.
            $this->application->instance('db.transactions', $this->priorInstance);
        }

        // Otherwise only the scope-owned instance is forgotten: the original
        // binding stays intact and keeps its exact resolution semantics.
    }

    /**
     * Read the manager currently embedded in the connection. Connection ships
     * no public getter for its `$transactionsManager`, but begin() must know
     * the exact prior value — an outer scope's or the application's own
     * manager — to hand back at close(); only the reflection view provides it.
     */
    private static function connectionTransactionManager(Connection $connection): ?object
    {
        $property = new \ReflectionProperty(Connection::class, 'transactionsManager');

        $manager = $property->getValue($connection);

        \assert($manager === null || \is_object($manager));

        return $manager;
    }
}
