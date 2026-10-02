<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\AvcoPool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AvcoPool */
class AvcoPoolResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AvcoPool $pool */
        $pool = $this->resource;

        return [
            'id' => $pool->id,
            'item_id' => $pool->item_id,
            'item' => $this->whenLoaded('item', fn () => new ItemResource($pool->item)),
            'scope' => $pool->scope->value,
            'warehouse_id' => $pool->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $pool->warehouse === null ? null : new WarehouseResource($pool->warehouse)),
            'batch_id' => $pool->batch_id,
            'batch' => $this->whenLoaded('batch', fn () => $pool->batch === null ? null : new StockBatchResource($pool->batch)),
            'qty_on_hand' => $pool->qty_on_hand,
            'inventory_value' => $this->when($request->user()?->can('inventory.valuation.view'), $pool->inventory_value),
            'avg_unit_cost' => $this->when($request->user()?->can('inventory.valuation.view'), $pool->avg_unit_cost),
            'base_avg_unit_cost' => $this->when($request->user()?->can('inventory.valuation.view'), $pool->base_avg_unit_cost),
            'last_valid_avg_unit_cost' => $this->when($request->user()?->can('inventory.valuation.view'), $pool->last_valid_avg_unit_cost),
            'status' => $pool->status->value,
        ];
    }
}
