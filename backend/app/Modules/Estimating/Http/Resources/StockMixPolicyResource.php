<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\StockMixPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockMixPolicy */
class StockMixPolicyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var StockMixPolicy $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'scope_type' => $row->scope_type->value,
            'scope_id' => $row->scope_id,
            'scope_name' => $row->scope_name,
            'level' => $row->level === null ? null : ['code' => $row->level->value, 'label' => (string) $row->level->label()],
            'threshold_percent' => $row->threshold_percent,
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
