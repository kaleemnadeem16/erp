<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Modules\Sales\Support\Kernel\OperationIntent;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Support\Scope\CompanyAccessFence;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * Runs one governed quotation operation exactly once per (company, actor, key), following the CRM convention (`CrmOperation`): the same key with the same
 * intent returns the original result even after later changes — replay is looked up before any freshness check — and the same key with a different intent is
 * refused (`REQUEST_KEY_REUSED`). The subject is locked first so concurrent identical requests serialise and the loser replays; a lost race on the receipt's
 * unique key is retried once outside the rolled-back transaction.
 */
final class QuotationOperation
{
    /** Whole-operation restarts after a deadlock or serialisation failure victim (the outermost transaction only). */
    public const DEADLOCK_RETRIES = 2;

    public const KEY_MAX = 85;

    public function __construct(private readonly QuotationOperationReceipts $receipts, private readonly CompanyAccessFence $accessFence) {}

    /**
     * @param  array<string, mixed>  $intent  everything that gives the request its meaning: subject, expected generation, changed values
     * @param  Closure(): mixed  $lockSubject  locks the revision row (and nothing else) and returns it to the work; a create has no subject yet
     * @param  Closure(mixed): array<string, mixed>  $work  performs the change and returns its versioned result (`family_id`, optional `revision_id`)
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function run(SalesActorContext $actor, string $operation, ?string $key, array $intent, Closure $lockSubject, Closure $work, ?Closure $replayGuard = null): array
    {
        $key = trim((string) $key);
        if ($key === '' || mb_strlen($key) > self::KEY_MAX) {
            throw QuotationRefusal::make(QuotationRefusal::REQUEST_KEY_REQUIRED, 'A request key of at most '.self::KEY_MAX.' characters is required so that retrying cannot repeat the change.', 'request_key');
        }
        $hash = OperationIntent::hash($operation, $intent);

        $uniqueRetries = 0;
        $deadlockRetries = 0;
        while (true) {
            try {
                return DB::transaction(function () use ($actor, $operation, $key, $hash, $lockSubject, $work, $replayGuard): array {
                    // Acquire security first on each attempt; family and revision locks follow.
                    $this->accessFence->lockAll();
                    $subject = $lockSubject();
                    $receipt = $this->receipts->find($actor, $key);
                    if ($receipt !== null) {
                        if ($replayGuard !== null) {
                            $replayGuard($receipt['result']);
                        }
                        if ($receipt['payload_hash'] !== $hash) {
                            throw QuotationRefusal::make(QuotationRefusal::REQUEST_KEY_REUSED, 'That request key was already used for a different request.', 'request_key');
                        }

                        return ['result' => $receipt['result'], 'replayed' => true];
                    }
                    $result = $work($subject);
                    $this->receipts->record($actor, $key, $operation, $hash, $result);

                    return ['result' => $result, 'replayed' => false];
                });
            } catch (UniqueConstraintViolationException $race) {
                // Someone completed the same key between our look-up and our insert: try again and replay (or refuse) their receipt.
                if (! str_contains($race->getMessage(), 'sales_quotation_receipts_key_unique') || $uniqueRetries++ >= 1) {
                    throw $race;
                }
            } catch (PDOException $failure) {
                // A deadlock or serialisation failure aborted this attempt (PostgreSQL chose it as the victim). Restart the WHOLE operation — the fence, the authority check in
                // lockSubject, the receipt look-up and the work all run again from the start — but only as the outermost transaction: inside a caller's, the aborted transaction
                // cannot continue, so the failure goes back to the caller.
                if (! self::isConcurrencyVictim($failure) || $deadlockRetries++ >= self::DEADLOCK_RETRIES || DB::transactionLevel() > 0) {
                    throw $failure;
                }
            }
        }
    }

    /** A deadlock (40P01) or serialisation failure (40001), also when Laravel has wrapped it in a DeadlockException on leaving a nested transaction. */
    private static function isConcurrencyVictim(Throwable $failure): bool
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && in_array((string) ($cause->errorInfo[0] ?? ''), ['40P01', '40001'], true)) {
                return true;
            }
        }

        return $failure instanceof DeadlockException;
    }
}
