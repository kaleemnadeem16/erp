<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\OperationReceipt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Runs one governed CRM operation exactly once per (company, actor, key) (ADRs 0111–0114). The same key with the same intent returns the original
 * result — even after later changes, because replay is looked up before any freshness check — while the same key with a different intent is
 * refused (`REQUEST_KEY_REUSED`). The subject is locked first, so concurrent identical requests serialise and the loser replays; a lost race on the
 * receipt's unique key is retried once outside the rolled-back transaction.
 */
final class CrmOperation
{
    public const KEY_MAX = 85;

    /**
     * @param  array<string, mixed>  $intent  everything that gives the request its meaning: subject, expected markers, reason, changed values
     * @param  \Closure(): mixed  $lockSubject  locks the lead or opportunity row (and nothing else)
     * @param  \Closure(): array<string, mixed>  $work  performs the change and returns its immutable, versioned result
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public static function run(User $actor, string $operation, ?string $key, array $intent, ?int $leadId, ?int $opportunityId, \Closure $lockSubject, \Closure $work): array
    {
        $key = trim((string) $key);
        if ($key === '' || mb_strlen($key) > self::KEY_MAX) {
            throw new DomainInvariantException('A request key of at most '.self::KEY_MAX.' characters is required so that retrying cannot repeat the change.', 'request_key', 'REQUEST_KEY_REQUIRED');
        }
        $hash = self::hash($intent + ['operation' => $operation, 'lead_id' => $leadId, 'opportunity_id' => $opportunityId]);

        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $operation, $key, $hash, $leadId, $opportunityId, $lockSubject, $work): array {
                    $lockSubject();
                    $receipt = OperationReceipt::query()->where('actor_id', $actor->id)->where('request_key', $key)->first();
                    if ($receipt !== null) {
                        if ($receipt->payload_hash !== $hash) {
                            throw new DomainInvariantException('That request key was already used for a different request.', 'request_key', 'REQUEST_KEY_REUSED');
                        }

                        return ['result' => $receipt->result, 'replayed' => true];
                    }
                    $result = $work();
                    OperationReceipt::create([
                        'actor_id' => $actor->id, 'request_key' => $key, 'operation' => $operation, 'lead_id' => $leadId, 'opportunity_id' => $opportunityId,
                        'payload_hash' => $hash, 'completed_at' => now(), 'result' => $result,
                    ]);

                    return ['result' => $result, 'replayed' => false];
                });
            } catch (UniqueConstraintViolationException $race) {
                // Someone completed the same key between our look-up and our insert: try again and replay (or refuse) their receipt.
                if ($attempt >= 1) {
                    throw $race;
                }
            }
        }
    }

    /** @param array<string, mixed> $intent */
    public static function hash(array $intent): string
    {
        return hash('sha256', json_encode(self::canonical($intent), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
