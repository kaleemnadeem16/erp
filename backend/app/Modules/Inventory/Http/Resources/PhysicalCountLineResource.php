<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\PhysicalCountLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PhysicalCountLine */
class PhysicalCountLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PhysicalCountLine $line */
        $line = $this->resource;

        return [
            'id' => $line->id,
            'physical_count_id' => $line->physical_count_id,
            'item_id' => $line->item_id,
            'item' => $this->whenLoaded('item', fn () => new ItemResource($line->item)),
            'location_id' => $line->location_id,
            'location' => $this->whenLoaded('location', fn () => new LocationResource($line->location)),
            'batch_id' => $line->batch_id,
            'serial_no' => $line->serial_no,
            'book_qty' => $line->book_qty,
            'counted_qty' => $line->counted_qty,
            'counted_by' => $line->counted_by,
            'counted_at' => $line->counted_at?->toIso8601String(),
            'found_unit_cost' => $line->found_unit_cost,
            'posted_stock_move_id' => $line->posted_stock_move_id,
            'entries' => PhysicalCountLineEntryResource::collection($this->whenLoaded('entries')),
        ];
    }
}
