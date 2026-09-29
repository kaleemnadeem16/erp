<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\MaterialVariancePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaterialVariancePolicy */
class MaterialVariancePolicyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MaterialVariancePolicy $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'scope_type' => $row->scope_type->value,
            'scope_id' => $row->scope_id,
            'minimum_effect' => $row->minimum_effect?->value,
            'reason' => $row->reason,
            'status' => $row->status->value,
            'effective_from' => $row->effective_from?->toDateString(),
            'effective_to' => $row->effective_to?->toDateString(),
            'changed_by' => $row->changed_by,
            'changed_by_user' => $this->whenLoaded('changedBy', fn () => [
                'id' => $row->changedBy->id, 'name' => $row->changedBy->name,
            ]),
            'bands' => MaterialVariancePolicyBandResource::collection($this->whenLoaded('bands')),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
