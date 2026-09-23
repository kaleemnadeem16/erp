<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\RateDimension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RateDimension */
class RateDimensionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RateDimension $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'sort_order' => $row->sort_order,
            'is_required' => $row->is_required,
            'values' => $this->whenLoaded('values', fn () => RateDimensionValueResource::collection($row->values)),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
