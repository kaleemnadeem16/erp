<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Kernel;

/**
 * What the kernel needs from an operation-receipt store, and nothing more (AU5 interface). Implementations belong to the owning slices — the CRM already
 * has `sales_crm_operation_receipts` and a quotation/pricing receipt will arrive with its own tables — so this batch adds no table and no implementation.
 * The convention is the existing one: one receipt per (company, actor, key); the same key and intent replays the stored result; a different intent refuses.
 */
interface OperationReceiptPort
{
    /** @return array{payload_hash: string, result: array<string, mixed>}|null */
    public function find(SalesActorContext $actor, string $requestKey): ?array;

    /** @param  array<string, mixed>  $result */
    public function record(SalesActorContext $actor, string $requestKey, string $operation, string $payloadHash, array $result): void;
}
