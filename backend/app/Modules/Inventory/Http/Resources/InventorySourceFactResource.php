<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\InventorySourceFact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventorySourceFact */
class InventorySourceFactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InventorySourceFact $fact */
        $fact = $this->resource;

        return [
            'id' => $fact->id,
            'item_id' => $fact->item_id,
            'location_id' => $fact->location_id,
            'uom_id' => $fact->uom_id,
            'source_valuation_method' => $fact->source_valuation_method->value,
            'source_avco_scope' => $fact->source_avco_scope?->value,
            'batch_no' => $fact->batch_no,
            'serial_no' => $fact->serial_no,
            'qty' => $fact->qty,
            'unit_cost' => $fact->unit_cost,
            'amount' => $fact->amount,
            'base_amount' => $fact->base_amount,
            'fx_rate' => $fact->fx_rate,
            'source_effective_at' => $fact->source_effective_at?->toIso8601String(),
            'source_posting_date' => $fact->source_posting_date?->toDateString(),
        ];
    }
}
