<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NegativeStockOverrideRequest */
class NegativeStockOverrideRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var NegativeStockOverrideRequest $override */
        $override = $this->resource;

        return [
            'id' => $override->id,
            'item_id' => $override->item_id,
            'item' => $this->whenLoaded('item', fn () => new ItemResource($override->item)),
            'location_id' => $override->location_id,
            'location' => $this->whenLoaded('location', fn () => new LocationResource($override->location)),
            'batch_id' => $override->batch_id,
            'batch' => $this->whenLoaded('batch', fn () => new StockBatchResource($override->batch)),
            'movement_class' => $override->movement_class->value,
            'requested_qty' => $override->requested_qty,
            'available_qty' => $override->available_qty,
            'shortage_qty' => $override->shortage_qty,
            'source_type' => $override->source_type,
            'source_id' => $override->source_id,
            'reason_code' => $override->reason_code->value,
            'explanation' => $override->explanation,
            'authorisation_mode' => $override->authorisation_mode->value,
            'allow_self_review' => $override->allow_self_review,
            'requested_by' => $override->requested_by,
            'requested_at' => $override->requested_at->toIso8601String(),
            'review_due_at' => $override->review_due_at?->toIso8601String(),
            'approval' => $this->whenLoaded('approval', fn () => $override->approval === null ? null : new NegativeStockOverrideApprovalResource($override->approval)),
            'review' => $this->whenLoaded('review', fn () => $override->review === null ? null : new NegativeStockOverrideReviewResource($override->review)),
        ];
    }
}
