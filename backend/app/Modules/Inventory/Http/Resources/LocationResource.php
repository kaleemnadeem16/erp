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

        return ['id' => $location->id, 'warehouse_id' => $location->warehouse_id, 'code' => $location->code, 'name' => $location->name, 'active' => $location->active, 'warehouse' => $this->whenLoaded('warehouse', fn () => new WarehouseResource($location->warehouse)), 'created_at' => $location->created_at?->toIso8601String(), 'updated_at' => $location->updated_at?->toIso8601String()];
    }
}
