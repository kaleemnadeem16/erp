<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\InventoryCutoverNegativeOpening;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryCutoverNegativeOpening */
class InventoryCutoverNegativeOpeningResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InventoryCutoverNegativeOpening $exception */
        $exception = $this->resource;

        return [
            'id' => $exception->id,
            'inventory_cutover_id' => $exception->inventory_cutover_id,
            'item_id' => $exception->item_id,
            'item' => $this->whenLoaded('item', fn () => new ItemResource($exception->item)),
            'location_id' => $exception->location_id,
            'batch_id' => $exception->batch_id,
            'source_valuation_method' => $exception->source_valuation_method->value,
            'source_quantity' => $exception->source_quantity,
            'source_value' => $exception->source_value,
            'base_source_value' => $exception->base_source_value,
            'provisional_unit_cost' => $exception->provisional_unit_cost,
            'reason' => $exception->reason,
            'supporting_evidence' => $exception->supporting_evidence,
            'responsible_owner_id' => $exception->responsible_owner_id,
            'resolution_plan' => $exception->resolution_plan,
            'target_date' => $exception->target_date->toDateString(),
            'target_date_reason' => $exception->target_date_reason,
            'status' => $exception->status->value,
            'requested_by' => $exception->requested_by,
            'requested_at' => $exception->requested_at?->toIso8601String(),
            'approved_by' => $exception->approved_by,
            'approved_at' => $exception->approved_at?->toIso8601String(),
        ];
    }
}
