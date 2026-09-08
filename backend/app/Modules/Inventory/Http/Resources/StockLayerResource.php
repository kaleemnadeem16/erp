<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\StockLayer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockLayer */
class StockLayerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var StockLayer $layer */
        $layer = $this->resource;

        return [
            'id' => $layer->id,
            'item_id' => $layer->item_id,
            'item' => $this->whenLoaded('item', fn () => new ItemResource($layer->item)),
            'location_id' => $layer->location_id,
            'location' => $this->whenLoaded('location', fn () => new LocationResource($layer->location)),
            'batch_id' => $layer->batch_id,
            'batch' => $this->whenLoaded('batch', fn () => $layer->batch === null ? null : new StockBatchResource($layer->batch)),
            'serial_no' => $layer->serial_no,
            'qty_received' => $layer->qty_received,
            'qty_remaining' => $layer->qty_remaining,
            'unit_cost' => $layer->unit_cost,
            'currency_id' => $layer->currency_id,
            'fx_rate' => $layer->fx_rate,
            'base_unit_cost' => $layer->base_unit_cost,
            'received_at' => $layer->received_at->toIso8601String(),
            'source_type' => $layer->source_type,
            'source_id' => $layer->source_id,
            'source_line_no' => $layer->source_line_no,
        ];
    }
}
