<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApplicationRate */
class ApplicationRateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ApplicationRate $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'item_id' => $row->item_id,
            'work_uom_id' => $row->work_uom_id,
            'qty_per_work_unit' => $row->qty_per_work_unit,
            'uom_id' => $row->uom_id,
            'is_default' => $row->is_default,
            // Always loaded by the controller/Action — a rate's dimension-value
            // combination is core to what it means, not an optional expansion.
            'dimension_value_ids' => $row->dimensionValues->pluck('id')->values()->all(),
            'dimension_values' => RateDimensionValueResource::collection($row->dimensionValues),
            'item' => $this->whenLoaded('item', fn () => new ItemResource($row->item)),
            'work_uom' => $this->whenLoaded('workUom', fn () => ['id' => $row->workUom->id, 'code' => $row->workUom->code, 'name' => $row->workUom->name]),
            'uom' => $this->whenLoaded('uom', fn () => ['id' => $row->uom->id, 'code' => $row->uom->code, 'name' => $row->uom->name]),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
