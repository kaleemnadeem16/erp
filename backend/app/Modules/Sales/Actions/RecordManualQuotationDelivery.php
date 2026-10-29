<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\QuotationDocumentDelivery;
use App\Modules\Sales\Support\Documents\QuotationDocumentLocator;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records that the official quotation PDF was handed to the customer by hand or outside the system. Needs `sales.delivery.record` AND the right to see the quotation; the
 * document must be the generated ORIGINAL of that issued quotation. Idempotent: the same actor, key and content is the same record; other content under that key is refused.
 * A correction names the record it replaces (one correction per record). This is the person's statement only — it is not receipt, reading or acceptance, and the system sends
 * nothing. INTERNAL until the route exists; the duty is STAGED.
 */
final class RecordManualQuotationDelivery
{
    public const CHANNELS = ['HAND', 'COURIER', 'POST', 'OTHER'];

    public function __construct(private readonly QuotationDocumentLocator $locator) {}

    /**
     * @param  array{channel: string, delivered_on: string, recipient_name: string, recipient_detail?: string|null, note?: string|null}  $fields
     * @return array{delivery: QuotationDocumentDelivery, replayed: bool}
     */
    public function __invoke(User $actor, int $revisionId, array $fields, string $operationKey, ?int $correctsId = null): array
    {
        if (! QuotationDocumentLocator::mayUse($actor, PermissionVocabulary::DELIVERY_RECORD)) {
            throw new AuthorizationException('You are not permitted to record a quotation delivery.');
        }
        $operationKey = trim($operationKey);
        $recipient = trim($fields['recipient_name']);
        $detail = isset($fields['recipient_detail']) ? trim((string) $fields['recipient_detail']) : null;
        $note = isset($fields['note']) ? trim((string) $fields['note']) : null;
        if ($operationKey === '' || mb_strlen($operationKey) > 120 || $recipient === '' || mb_strlen($recipient) > 200 || ($detail !== null && mb_strlen($detail) > 200)
            || ($note !== null && mb_strlen($note) > 1000) || ! in_array($fields['channel'], self::CHANNELS, true)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['delivered_on']) !== 1 || strtotime($fields['delivered_on']) === false
            || $fields['delivered_on'] > now()->addDay()->format('Y-m-d')) {
            throw new DomainInvariantException('A delivery record needs a request key, a recipient, a channel (HAND, COURIER, POST, OTHER) and the actual date, which cannot be in the future.', 'delivery', 'DELIVERY_INVALID');
        }
        $artefact = $this->locator->artefactOrFail($revisionId);
        $digest = hash('sha256', json_encode([$revisionId, $artefact->id, $fields['channel'], $fields['delivered_on'], $recipient, $detail === '' ? null : $detail, $note === '' ? null : $note, $correctsId], JSON_THROW_ON_ERROR));

        $replay = function (QuotationDocumentDelivery $row) use ($digest): array {
            if ($row->intent_digest !== $digest) {
                throw new DomainInvariantException('That request key was already used for a different delivery record.', 'operation_key', 'DELIVERY_KEY_REUSED');
            }

            return ['delivery' => $row, 'replayed' => true];
        };
        if (($row = $this->existing($actor, $operationKey)) !== null) {
            return $replay($row);
        }
        if ($correctsId !== null && ! QuotationDocumentDelivery::query()->where(['id' => $correctsId, 'revision_id' => $revisionId])->exists()) {
            throw new DomainInvariantException('The record to correct was not found.', 'corrects_id', 'DELIVERY_NOT_FOUND');
        }

        try {
            return DB::transaction(fn (): array => ['delivery' => QuotationDocumentDelivery::create([
                'revision_id' => $revisionId, 'artefact_id' => $artefact->id, 'recorded_by' => $actor->id, 'channel' => $fields['channel'], 'delivered_on' => $fields['delivered_on'],
                'recipient_name' => $recipient, 'recipient_detail' => $detail === '' ? null : $detail, 'note' => $note === '' ? null : $note, 'corrects_id' => $correctsId,
                'operation_key' => $operationKey, 'intent_digest' => $digest, 'recorded_at' => now(),
            ])->refresh(), 'replayed' => false]);
        } catch (UniqueConstraintViolationException) {
            if (($row = $this->existing($actor, $operationKey)) !== null) {
                return $replay($row);
            }
            throw new DomainInvariantException('That record has already been corrected.', 'corrects_id', 'DELIVERY_ALREADY_CORRECTED');
        }
    }

    /** @phpstan-impure */
    private function existing(User $actor, string $operationKey): ?QuotationDocumentDelivery
    {
        return QuotationDocumentDelivery::query()->where(['recorded_by' => $actor->id, 'operation_key' => $operationKey])->first();
    }
}
