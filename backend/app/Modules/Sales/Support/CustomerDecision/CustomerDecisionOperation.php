<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\CustomerDecision;

use App\Models\User;
use App\Modules\Sales\Models\CustomerDecisionReceipt;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Runs one customer-decision operation once: in one transaction, the subject is locked (and the caller's authority judged again, inside the attempt), the actor's receipt for the
 * key is looked up BEFORE any work (the same key and intent replays the stored result, a different intent is refused), the work runs, and its receipt is recorded with it.
 */
final class CustomerDecisionOperation
{
    public const KEY_MAX = 85;

    /**
     * @param  array<string, mixed>  $intent  everything that gives the request its meaning
     * @param  Closure(): mixed  $lockSubject  judges authority again and locks what the work will change; runs first in every attempt
     * @param  Closure(mixed): array<string, mixed>  $work  performs the change and returns its versioned result (`case_id` required)
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function run(User $actor, string $operation, ?string $key, array $intent, Closure $lockSubject, Closure $work): array
    {
        $key = trim((string) $key);
        if ($key === '' || mb_strlen($key) > self::KEY_MAX) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::REQUEST_KEY_REQUIRED, 'A request key of at most '.self::KEY_MAX.' characters is required so that retrying cannot repeat the change.', 'request_key');
        }
        $hash = OperationIntent::hash($operation, $intent);

        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $operation, $key, $hash, $lockSubject, $work): array {
                    $subject = $lockSubject();
                    $receipt = CustomerDecisionReceipt::query()->where(['actor_id' => $actor->id, 'request_key' => $key])->first();
                    if ($receipt !== null) {
                        if ($receipt->payload_hash !== $hash) {
                            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::REQUEST_KEY_REUSED, 'That request key was already used for a different request.', 'request_key');
                        }

                        return ['result' => $receipt->result, 'replayed' => true];
                    }
                    $result = $work($subject);
                    CustomerDecisionReceipt::create(['actor_id' => $actor->id, 'request_key' => $key, 'operation' => $operation, 'case_id' => $result['case_id'], 'payload_hash' => $hash,
                        'completed_at' => now(), 'result' => $result]);

                    return ['result' => $result, 'replayed' => false];
                });
            } catch (UniqueConstraintViolationException $race) {
                // Someone completed the same key between our look-up and our insert: try again and replay (or refuse) their receipt.
                if ($attempt >= 1 || ! str_contains($race->getMessage(), 'sales_customer_decision_receipts_key_unique')) {
                    throw $race;
                }
            }
        }
    }
}
