<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\SupplierReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupplierReturn */
class SupplierReturnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SupplierReturn $document */
        $document = $this->resource;

        return [
            'id' => $document->id, 'number' => $document->number, 'status' => $document->status, 'warehouse_id' => $document->warehouse_id, 'reason' => $document->reason,
            'supplier_account_id' => $document->supplier_account_id, 'supplier_reference' => $document->supplier_reference, 'carrier_note' => $document->carrier_note,
            // Information only: no credit note, supplier balance or journal exists for it (ADR 0097 §5).
            'expected_credit_amount' => $document->expected_credit_amount, 'expected_credit_currency_id' => $document->expected_credit_currency_id,
            'expected_credit_reference' => $document->expected_credit_reference,
            'created_by' => $document->created_by, 'created_at' => $document->created_at?->toIso8601String(), 'posted_by' => $document->posted_by,
            'posted_at' => $document->posted_at?->toIso8601String(), 'cancelled_by' => $document->cancelled_by, 'cancelled_at' => $document->cancelled_at?->toIso8601String(),
            'lines' => SupplierReturnLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
