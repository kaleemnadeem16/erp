<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\OverheadPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OverheadPolicy */
class OverheadPolicyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OverheadPolicy $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'scope_type' => $row->scope_type->value,
            'scope_id' => $row->scope_id,
            'driver_type' => $row->driver_type->value,
            'rate' => $this->when(
                $request->user()?->can('estimating.overhead-policy.manage')
                    || $request->user()?->can('estimating.policy-history.view')
                    || $request->user()?->can('estimating.internal_cost.view'),
                $row->rate,
            ),
            'reason' => $row->reason,
            'status' => $row->status->value,
            'effective_from' => $row->effective_from?->toDateString(),
            'effective_to' => $row->effective_to?->toDateString(),
            'changed_by' => $row->changed_by,
            'changed_by_user' => $this->whenLoaded('changedBy', fn () => [
                'id' => $row->changedBy->id, 'name' => $row->changedBy->name,
            ]),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
