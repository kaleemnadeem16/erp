<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\MaterialSelectionPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaterialSelectionPolicy */
class MaterialSelectionPolicyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MaterialSelectionPolicy $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'scope_type' => $row->scope_type->value,
            'scope_id' => $row->scope_id,
            'scope_name' => $row->scope_name,
            'strategy' => $row->strategy === null ? null : ['code' => $row->strategy->value, 'label' => (string) $row->strategy->label()],
            'reason' => $row->reason,
            'status' => $row->status->value,
            'effective_from' => $row->effective_from->toIso8601String(),
            'effective_to' => $row->effective_to?->toIso8601String(),
            'changed_by' => $row->changed_by,
            'changed_by_user' => $this->whenLoaded('changedBy', fn () => [
                'id' => $row->changedBy->id, 'name' => $row->changedBy->name,
            ]),
        ];
    }
}
