<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Location */
class LocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {/** @var Location $location */ $location = $this->resource;

        return [
            'id' => $location->id,
            'warehouse_id' => $location->warehouse_id,
            'parent_id' => $location->parent_id,
            'code' => $location->code,
            'name' => $location->name,
            'type' => $location->type->value,
            'barcode' => $location->barcode,
            'sort_order' => $location->sort_order,
            'allows_stock' => $location->allows_stock,
            'counts_as_usable' => $location->counts_as_usable,
            'counts_as_usable_effective' => $location->countsAsUsable(),
            'active' => $location->active,
            'warehouse' => $this->whenLoaded('warehouse', fn () => new WarehouseResource($location->warehouse)),
            'parent' => $this->whenLoaded('parent', fn () => new self($location->parent)),
            'children' => self::collection($this->whenLoaded('children')),
            'created_at' => $location->created_at?->toIso8601String(),
            'updated_at' => $location->updated_at?->toIso8601String(),
        ];
    }
}
