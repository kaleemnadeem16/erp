<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\PhysicalCountLineEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PhysicalCountLineEntry */
class PhysicalCountLineEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PhysicalCountLineEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'counted_qty' => $entry->counted_qty,
            'counted_by' => $entry->counted_by,
            'counted_at' => $entry->counted_at->toIso8601String(),
        ];
    }
}
