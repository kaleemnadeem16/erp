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
 * Records that the official quotation PDF was handed to the customer by hand or outside the system. Needs `sales.delivery.record` AND the right to see the quotation; the
 * document must be the generated ORIGINAL of that issued quotation. Idempotent: the same actor, key and content is the same record; other content under that key is refused.
 * Corrections and withdrawals are a different Action (CorrectManualQuotationDelivery) and always carry a reason (ADR 0181). This is the person's statement only — it is not receipt, reading or acceptance, and the system sends
 * nothing. INTERNAL until the route exists; the duty is STAGED.
 */
final class RecordManualQuotationDelivery
{
    public const CHANNELS = ['HAND', 'COURIER', 'POST', 'OTHER'];

    public function __construct(private readonly QuotationDocumentLocator $locator, private readonly DeliveryDateWindow $window) {}

    /**
     * @param  array{channel: string, delivered_on: string, recipient_name: string, recipient_detail?: string|null, note?: string|null}  $fields
     * @return array{delivery: QuotationDocumentDelivery, replayed: bool}
     */
    public function __invoke(User $actor, int $revisionId, array $fields, string $operationKey): array
    {
        // Authority, the replay look-up and the insert share one transaction, so a replay is only disclosed to someone who may see it now.
        return DB::transaction(function () use ($actor, $revisionId, $fields, $operationKey): array {
            SalesAccess::require($this->locator->currentActor($actor), PermissionVocabulary::DELIVERY_RECORD, 'record a quotation delivery');
            $this->locator->authorise($actor, $revisionId, 'record a quotation delivery');

            return $this->record($actor, $revisionId, $fields, $operationKey);
        });
    }

    /**
     * @param  array{channel: string, delivered_on: string, recipient_name: string, recipient_detail?: string|null, note?: string|null}  $fields
     * @return array{delivery: QuotationDocumentDelivery, replayed: bool}
     */
    private function record(User $actor, int $revisionId, array $fields, string $operationKey): array
    {
        $operationKey = trim($operationKey);
        $recipient = trim($fields['recipient_name']);
        $detail = isset($fields['recipient_detail']) ? trim((string) $fields['recipient_detail']) : null;
        $note = isset($fields['note']) ? trim((string) $fields['note']) : null;
        $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $fields['delivered_on']) === 1
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $fields['delivered_on']) : false;
        $maximumDate = $this->window->latest();
        if ($operationKey === '' || mb_strlen($operationKey) > 120 || $recipient === '' || mb_strlen($recipient) > 200 || ($detail !== null && mb_strlen($detail) > 200)
            || ($note !== null && mb_strlen($note) > 1000) || ! in_array($fields['channel'], self::CHANNELS, true)
            || ! $date || $date->format('Y-m-d') !== $fields['delivered_on']
            || $fields['delivered_on'] > $maximumDate) {
            throw new DomainInvariantException('A delivery record needs a request key, a recipient, a channel (HAND, COURIER, POST, OTHER) and the actual date, which cannot be in the future.', 'delivery', 'DELIVERY_INVALID');
        }
        $artefact = $this->locator->artefactOrFail($revisionId);
        $digest = hash('sha256', json_encode([$revisionId, $artefact->id, $fields['channel'], $fields['delivered_on'], $recipient, $detail === '' ? null : $detail, $note === '' ? null : $note], JSON_THROW_ON_ERROR));

        $replay = function (QuotationDocumentDelivery $row) use ($digest): array {
            if ($row->intent_digest !== $digest) {
                throw new DomainInvariantException('That request key was already used for a different delivery record.', 'operation_key', 'DELIVERY_KEY_REUSED');
            }

            return ['delivery' => $row, 'replayed' => true];
        };
        if (($row = $this->existing($actor, $operationKey)) !== null) {
            return $replay($row);
        }

        try {
            return DB::transaction(fn (): array => ['delivery' => QuotationDocumentDelivery::create([
                'revision_id' => $revisionId, 'artefact_id' => $artefact->id, 'recorded_by' => $actor->id, 'channel' => $fields['channel'], 'delivered_on' => $fields['delivered_on'],
                'recipient_name' => $recipient, 'recipient_detail' => $detail === '' ? null : $detail, 'note' => $note === '' ? null : $note,
                'operation_key' => $operationKey, 'intent_digest' => $digest, 'recorded_at' => now(),
            ])->refresh(), 'replayed' => false]);
        } catch (UniqueConstraintViolationException $conflict) {
            if (($row = $this->existing($actor, $operationKey)) !== null) {
                return $replay($row);
            }
            throw $conflict;
        }
    }

    /** @phpstan-impure */
    private function existing(User $actor, string $operationKey): ?QuotationDocumentDelivery
    {
        return QuotationDocumentDelivery::query()->where(['recorded_by' => $actor->id, 'operation_key' => $operationKey])->first();
    }
}
