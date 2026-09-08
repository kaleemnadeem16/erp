<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\RecalculationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecalculationRequest */
class RecalculationRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RecalculationRequest $recalculation */
        $recalculation = $this->resource;

        return [
            'id' => $recalculation->id,
            'item_id' => $recalculation->item_id,
            'item' => $this->whenLoaded('item', fn () => new ItemResource($recalculation->item)),
            'location_id' => $recalculation->location_id,
            'location' => $this->whenLoaded('location', fn () => new LocationResource($recalculation->location)),
            'destination_location_id' => $recalculation->destination_location_id,
            'destination_location' => $this->whenLoaded('destinationLocation', fn () => $recalculation->destinationLocation === null ? null : new LocationResource($recalculation->destinationLocation)),
            'batch_id' => $recalculation->batch_id,
            'batch' => $this->whenLoaded('batch', fn () => $recalculation->batch === null ? null : new StockBatchResource($recalculation->batch)),
            'serial_no' => $recalculation->serial_no,
            'movement_kind' => $recalculation->movement_kind->value,
            'qty' => $recalculation->qty,
            'unit_cost' => $recalculation->unit_cost,
            'currency_id' => $recalculation->currency_id,
            'fx_rate' => $recalculation->fx_rate,
            'base_unit_cost' => $recalculation->base_unit_cost,
            'moved_at' => $recalculation->moved_at->toIso8601String(),
            'source_type' => $recalculation->source_type,
            'source_id' => $recalculation->source_id,
            'horizon_mode' => $recalculation->horizon_mode->value,
            'horizon_permitted_floor' => $recalculation->horizon_permitted_floor?->toDateString(),
            'requested_by' => $recalculation->requested_by,
            'requested_at' => $recalculation->requested_at->toIso8601String(),
            'review_due_at' => $recalculation->review_due_at->toIso8601String(),
            'impact_versions' => RecalculationImpactVersionResource::collection($this->whenLoaded('impactVersions')),
        ];
    }
}
