<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\StockMove;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockMove */
class StockMoveResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var StockMove $move */
        $move = $this->resource;

        return [
            'id' => $move->id,
            'item_id' => $move->item_id,
            'item' => $this->whenLoaded('item', fn () => new ItemResource($move->item)),
            'from_location_id' => $move->from_location_id,
            'from_location' => $this->whenLoaded('fromLocation', fn () => new LocationResource($move->fromLocation)),
            'to_location_id' => $move->to_location_id,
            'to_location' => $this->whenLoaded('toLocation', fn () => new LocationResource($move->toLocation)),
            'qty' => $move->qty,
            'uom_id' => $move->uom_id,
            'moved_at' => $move->moved_at->toIso8601String(),
            'source_type' => $move->source_type,
            'source_id' => $move->source_id,
            'unit_cost' => $move->unit_cost,
            'currency_id' => $move->currency_id,
            'fx_rate' => $move->fx_rate,
            'batch_id' => $move->batch_id,
            'batch' => $this->whenLoaded('batch', fn () => new StockBatchResource($move->batch)),
            'serial_no' => $move->serial_no,
            'valuation_entries' => StockValuationEntryResource::collection($this->whenLoaded('valuationEntries')),
            'created_at' => $move->created_at?->toIso8601String(),
        ];
    }
}
