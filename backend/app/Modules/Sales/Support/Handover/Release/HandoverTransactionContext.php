<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Proof, taken from the database itself, of WHICH session and transaction a release runs in: the default runtime connection, its enclosing transaction level, the
 * PostgreSQL backend pid and the transaction id. A caller-supplied boolean, flag or hash proves nothing; the Jobs receiver re-checks with `assertCurrent()` that it is still
 * inside exactly this transaction before it writes, so a receiver on another connection or after COMMIT cannot be mistaken for part of the release.
 */
final readonly class HandoverTransactionContext
{
    private function __construct(public string $connection, public int $level, public int $backendPid, public string $transactionId) {}

    /** @throws LogicException outside an enclosing transaction */
    public static function capture(): self
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('A release requires an enclosing transaction on the runtime connection.');
        }

        return new self(
            (string) $connection->getName(),
            $connection->transactionLevel(),
            (int) $connection->selectOne('select pg_backend_pid() as pid')->pid,
            (string) $connection->selectOne('select txid_current() as txid')->txid,
        );
    }

    /** @throws LogicException when the current connection, backend session or transaction is not the one captured */
    public function assertCurrent(): void
    {
        $now = self::capture();
        if ($now->connection !== $this->connection || $now->backendPid !== $this->backendPid || $now->transactionId !== $this->transactionId) {
            throw new LogicException('The release is no longer running in its original connection, session and transaction.');
        }
    }
}
