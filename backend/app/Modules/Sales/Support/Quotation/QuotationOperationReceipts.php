<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Modules\Sales\Models\QuotationOperationReceipt;
use App\Modules\Sales\Support\Kernel\OperationReceiptPort;
use App\Modules\Sales\Support\Kernel\SalesActorContext;

/** The quotation receipt store behind the kernel's `OperationReceiptPort`: one receipt per (company, actor, key), the company coming from the scoped model. */
final class QuotationOperationReceipts implements OperationReceiptPort
{
    public function find(SalesActorContext $actor, string $requestKey): ?array
    {
        $receipt = QuotationOperationReceipt::query()->where('actor_id', $actor->actor->id)->where('request_key', $requestKey)->first();

        return $receipt === null ? null : ['payload_hash' => $receipt->payload_hash, 'result' => $receipt->result];
    }

    public function record(SalesActorContext $actor, string $requestKey, string $operation, string $payloadHash, array $result): void
    {
        QuotationOperationReceipt::create([
            'actor_id' => $actor->actor->id, 'request_key' => $requestKey, 'operation' => $operation,
            'family_id' => $result['family_id'], 'revision_id' => $result['revision_id'] ?? null,
            'payload_hash' => $payloadHash, 'completed_at' => now(), 'result' => $result,
        ]);
    }
}
