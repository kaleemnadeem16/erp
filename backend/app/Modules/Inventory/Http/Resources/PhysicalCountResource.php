<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\PhysicalCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PhysicalCount */
class PhysicalCountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PhysicalCount $count */
        $count = $this->resource;

        return [
            'id' => $count->id,
            'warehouse_id' => $count->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => new WarehouseResource($count->warehouse)),
            'reference' => $count->reference,
            'status' => $count->status->value,
            'created_by' => $count->created_by,
            'created_at' => $count->created_at?->toIso8601String(),
            'posted_by' => $count->posted_by,
            'posted_at' => $count->posted_at?->toIso8601String(),
            'cancelled_by' => $count->cancelled_by,
            'cancelled_at' => $count->cancelled_at?->toIso8601String(),
            'lines' => PhysicalCountLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
