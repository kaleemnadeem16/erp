<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\ResourceRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ResourceRate */
class ResourceRateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ResourceRate $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'item_id' => $row->item_id,
            'rate' => $row->rate,
            'rate_uom_id' => $row->rate_uom_id,
            'effective_from' => $row->effective_from->toDateString(),
            'item' => $this->whenLoaded('item', fn () => ['id' => $row->item->id, 'code' => $row->item->code, 'name' => $row->item->name]),
            'rate_uom' => $this->whenLoaded('rateUom', fn () => ['id' => $row->rateUom->id, 'code' => $row->rateUom->code, 'name' => $row->rateUom->name]),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
