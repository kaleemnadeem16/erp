<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\RecalculationApproval;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecalculationApproval */
class RecalculationApprovalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RecalculationApproval $approval */
        $approval = $this->resource;

        return [
            'id' => $approval->id,
            'decision' => $approval->decision->value,
            'notes' => $approval->notes,
            'decided_by' => $approval->decided_by,
            'decided_at' => $approval->decided_at->toIso8601String(),
            'expires_at' => $approval->expires_at?->toIso8601String(),
            'is_expired' => $approval->isExpired(),
        ];
    }
}
