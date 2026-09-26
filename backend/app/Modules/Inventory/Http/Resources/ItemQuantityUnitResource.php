<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Http\Resources\UomResource;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ItemQuantityUnit */
class ItemQuantityUnitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ItemQuantityUnit $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'item_id' => $row->item_id,
            'item' => $this->whenLoaded('item', fn () => new ItemResource($row->item)),
            'uom_id' => $row->uom_id,
            'uom' => $this->whenLoaded('uom', fn () => new UomResource($row->uom)),
            'factor' => $row->factor,
            'quantity_precision' => $row->quantity_precision,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
