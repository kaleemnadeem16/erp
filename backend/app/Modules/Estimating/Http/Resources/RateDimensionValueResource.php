<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\RateDimensionValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RateDimensionValue */
class RateDimensionValueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RateDimensionValue $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'rate_dimension_id' => $row->rate_dimension_id,
            'code' => $row->code,
            'name' => $row->name,
            'sort_order' => $row->sort_order,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
