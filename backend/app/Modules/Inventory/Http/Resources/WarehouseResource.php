<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Warehouse */
class WarehouseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {/** @var Warehouse $warehouse */ $warehouse = $this->resource;

        return ['id' => $warehouse->id, 'code' => $warehouse->code, 'name' => $warehouse->name, 'is_default' => $warehouse->is_default, 'active' => $warehouse->active, 'locations' => $this->whenLoaded('locations', fn () => LocationResource::collection($warehouse->locations)), 'created_at' => $warehouse->created_at?->toIso8601String(), 'updated_at' => $warehouse->updated_at?->toIso8601String()];
    }
}
