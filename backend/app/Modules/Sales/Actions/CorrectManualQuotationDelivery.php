<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\QuotationDocumentDelivery;
use App\Modules\Sales\Support\Documents\DeliveryDateWindow;
use App\Modules\Sales\Support\Documents\QuotationDocumentLocator;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Corrects or withdraws one recorded manual delivery WITHOUT touching it: the evidence is append-only, so the correction is a new row that names the record it replaces and
 * says why. Two kinds: a CORRECTION carries the replacement values; a WITHDRAWAL says the delivery did not happen as recorded (it repeats the old values, the reason is the news).
 *
 * Rules (the accepted append-correction rule of ADR 0137 / DOC4; the docs are silent on the rest, so the safest default-deny applies and is recorded in the ADR):
 *  - Authority is the same as recording a delivery: `sales.delivery.record` plus the right to see the quotation. No new duty (duties stay STAGED).
 *  - Only the CURRENT record of a chain can be corrected: a record that already has a correction, or a withdrawn record, is refused (a delivery that really happened later is a new record).
 *  - A reason is always required (1 to 500 characters). Idempotent per actor and key; other content under the same key is refused.
 * Still a person's statement only: it is not receipt, reading or acceptance, and nothing is sent. INTERNAL behind the STAGED duty.
 */
final class CorrectManualQuotationDelivery
{
    public function __construct(private readonly QuotationDocumentLocator $locator, private readonly DeliveryDateWindow $window) {}

    /**
     * @param  array{channel: string, delivered_on: string, recipient_name: string, recipient_detail?: string|null, note?: string|null}|null  $fields  the replacement values; null withdraws
     * @return array{delivery: QuotationDocumentDelivery, replayed: bool}
     */
    public function __invoke(User $actor, int $revisionId, int $correctsId, ?array $fields, string $reason, string $operationKey): array
    {
        return DB::transaction(function () use ($actor, $revisionId, $correctsId, $fields, $reason, $operationKey): array {
            SalesAccess::require($this->locator->currentActor($actor), PermissionVocabulary::DELIVERY_RECORD, 'correct a quotation delivery');
            $this->locator->authorise($actor, $revisionId, 'correct a quotation delivery');

            return $this->correct($actor, $revisionId, $correctsId, $fields, $reason, $operationKey);
        });
    }

    /**
     * @param  array{channel: string, delivered_on: string, recipient_name: string, recipient_detail?: string|null, note?: string|null}|null  $fields
     * @return array{delivery: QuotationDocumentDelivery, replayed: bool}
     */
    private function correct(User $actor, int $revisionId, int $correctsId, ?array $fields, string $reason, string $operationKey): array
    {
        $operationKey = trim($operationKey);
        $reason = trim($reason);
        if ($operationKey === '' || mb_strlen($operationKey) > 120 || $reason === '' || mb_strlen($reason) > 500) {
            throw new DomainInvariantException('A correction needs a request key and a reason of up to 500 characters.', 'reason', 'DELIVERY_INVALID');
        }
        $this->locator->manifest($revisionId);
        $artefact = $this->locator->artefactOrFail($revisionId);

        $prior = QuotationDocumentDelivery::query()->where(['id' => $correctsId, 'revision_id' => $revisionId])->lockForUpdate()->first();
        $withdrawal = $fields === null;
        $values = $fields === null ? null : $this->validated($fields);
        $digest = hash('sha256', json_encode(['correction', $revisionId, $artefact->id, $correctsId, $withdrawal, $values, $reason], JSON_THROW_ON_ERROR));

        $existing = QuotationDocumentDelivery::query()->where(['recorded_by' => $actor->id, 'operation_key' => $operationKey])->first();
        if ($existing !== null) {
            if ($existing->intent_digest !== $digest) {
                throw new DomainInvariantException('That request key was already used for a different delivery record.', 'operation_key', 'DELIVERY_KEY_REUSED');
            }

            return ['delivery' => $existing, 'replayed' => true];
        }
        if ($prior === null) {
            throw new DomainInvariantException('The record to correct was not found.', 'corrects_id', 'DELIVERY_NOT_FOUND');
        }
        if ($prior->is_withdrawal) {
            throw new DomainInvariantException('That record was withdrawn and is final; record the delivery again as a new record.', 'corrects_id', 'DELIVERY_WITHDRAWN');
        }
        if (QuotationDocumentDelivery::query()->where('corrects_id', $prior->id)->exists()) {
            throw new DomainInvariantException('That record has already been corrected; correct the latest record instead.', 'corrects_id', 'DELIVERY_ALREADY_CORRECTED');
        }

        $values ??= [
            'channel' => $prior->channel, 'delivered_on' => $prior->delivered_on->format('Y-m-d'), 'recipient_name' => $prior->recipient_name,
            'recipient_detail' => $prior->recipient_detail, 'note' => $prior->note,
        ];
        try {
            return ['delivery' => QuotationDocumentDelivery::create([
                'revision_id' => $revisionId, 'artefact_id' => $artefact->id, 'recorded_by' => $actor->id, ...$values, 'corrects_id' => $prior->id,
                'correction_reason' => $reason, 'is_withdrawal' => $withdrawal, 'operation_key' => $operationKey, 'intent_digest' => $digest, 'recorded_at' => now(),
            ])->refresh(), 'replayed' => false];
        } catch (UniqueConstraintViolationException) {
            throw new DomainInvariantException('That record has already been corrected; correct the latest record instead.', 'corrects_id', 'DELIVERY_ALREADY_CORRECTED');
        }
    }

    /**
     * @param  array{channel: string, delivered_on: string, recipient_name: string, recipient_detail?: string|null, note?: string|null}  $fields
     * @return array{channel: string, delivered_on: string, recipient_name: string, recipient_detail: string|null, note: string|null}
     */
    private function validated(array $fields): array
    {
        $recipient = trim($fields['recipient_name']);
        $detail = isset($fields['recipient_detail']) ? trim((string) $fields['recipient_detail']) : null;
        $note = isset($fields['note']) ? trim((string) $fields['note']) : null;
        $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $fields['delivered_on']) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $fields['delivered_on']) : false;
        $maximumDate = $this->window->latest();
        if ($recipient === '' || mb_strlen($recipient) > 200 || ($detail !== null && mb_strlen($detail) > 200) || ($note !== null && mb_strlen($note) > 1000)
            || ! in_array($fields['channel'], RecordManualQuotationDelivery::CHANNELS, true) || ! $date || $date->format('Y-m-d') !== $fields['delivered_on'] || $fields['delivered_on'] > $maximumDate) {
            throw new DomainInvariantException('A corrected delivery needs a recipient, a channel (HAND, COURIER, POST, OTHER) and the actual date, which cannot be in the future.', 'delivery', 'DELIVERY_INVALID');
        }

        return ['channel' => $fields['channel'], 'delivered_on' => $fields['delivered_on'], 'recipient_name' => $recipient, 'recipient_detail' => $detail === '' ? null : $detail, 'note' => $note === '' ? null : $note];
    }
}
