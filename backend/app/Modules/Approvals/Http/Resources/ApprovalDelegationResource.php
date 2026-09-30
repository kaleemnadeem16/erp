<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Resources;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalDelegation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApprovalDelegation */
class ApprovalDelegationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ApprovalDelegation $row */
        $row = $this->resource;

        return [
            'id' => $row->id, 'delegator_id' => $row->delegator_id, 'delegate_id' => $row->delegate_id,
            'delegator_name' => $row->delegator?->name, 'delegate_name' => User::query()->whereKey($row->delegate_id)->value('name'),
            'subject_type' => $row->subject_type, 'valid_from' => $row->valid_from->toDateString(), 'valid_to' => $row->valid_to->toDateString(),
            'reason' => $row->reason, 'revoked_at' => $row->revoked_at?->toIso8601String(),
            'in_force' => $row->revoked_at === null && $row->valid_from->lte(today()) && $row->valid_to->gte(today()),
        ];
    }
}
