<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WarehouseLocationAssignment */
class WarehouseLocationAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WarehouseLocationAssignment $assignment */
        $assignment = $this->resource;

        return [
            'id' => $assignment->id,
            'warehouse_id' => $assignment->warehouse_id,
            'location_id' => $assignment->location_id,
            'role' => $assignment->role->value,
            'warehouse' => $this->whenLoaded('warehouse', fn () => new WarehouseResource($assignment->warehouse)),
            'location' => $this->whenLoaded('location', fn () => new LocationResource($assignment->location)),
            'created_at' => $assignment->created_at?->toIso8601String(),
            'updated_at' => $assignment->updated_at?->toIso8601String(),
        ];
    }
}
