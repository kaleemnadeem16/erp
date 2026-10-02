<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\MaterialVarianceReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaterialVarianceReason */
class MaterialVarianceReasonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MaterialVarianceReason $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'description' => $row->description,
            'active' => $row->active,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
