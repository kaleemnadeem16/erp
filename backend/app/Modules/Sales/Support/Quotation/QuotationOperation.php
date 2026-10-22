<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Modules\Sales\Support\Kernel\OperationIntent;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Runs one governed quotation operation exactly once per (company, actor, key), following the CRM convention (`CrmOperation`): the same key with the same
 * intent returns the original result even after later changes — replay is looked up before any freshness check — and the same key with a different intent is
 * refused (`REQUEST_KEY_REUSED`). The subject is locked first so concurrent identical requests serialise and the loser replays; a lost race on the receipt's
 * unique key is retried once outside the rolled-back transaction.
 */
final class QuotationOperation
{
    public const KEY_MAX = 85;

    public function __construct(private readonly QuotationOperationReceipts $receipts) {}

    /**
     * @param  array<string, mixed>  $intent  everything that gives the request its meaning: subject, expected generation, changed values
     * @param  Closure(): mixed  $lockSubject  locks the revision row (and nothing else) and returns it to the work; a create has no subject yet
     * @param  Closure(mixed): array<string, mixed>  $work  performs the change and returns its versioned result (`family_id`, optional `revision_id`)
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function run(SalesActorContext $actor, string $operation, ?string $key, array $intent, Closure $lockSubject, Closure $work): array
    {
        $key = trim((string) $key);
        if ($key === '' || mb_strlen($key) > self::KEY_MAX) {
            throw QuotationRefusal::make(QuotationRefusal::REQUEST_KEY_REQUIRED, 'A request key of at most '.self::KEY_MAX.' characters is required so that retrying cannot repeat the change.', 'request_key');
        }
        $hash = OperationIntent::hash($operation, $intent);

        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $operation, $key, $hash, $lockSubject, $work): array {
                    $subject = $lockSubject();
                    $receipt = $this->receipts->find($actor, $key);
                    if ($receipt !== null) {
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
                if ($attempt >= 1 || ! str_contains($race->getMessage(), 'sales_quotation_receipts_key_unique')) {
                    throw $race;
                }
            }
        }
    }
}
