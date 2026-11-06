<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Resources;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Numbering\Services\CompanyClock;
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

        // Validity is a business date: compare it with today's date in the company's time zone, as date strings.
        $today = app(CompanyClock::class)->today()->toDateString();

        return [
            'id' => $row->id, 'delegator_id' => $row->delegator_id, 'delegate_id' => $row->delegate_id,
            'delegator_name' => (string) $row->delegator?->name, 'delegate_name' => (string) User::query()->whereKey($row->delegate_id)->value('name'),
            'subject_type' => $row->subject_type, 'valid_from' => $row->valid_from->toDateString(), 'valid_to' => $row->valid_to->toDateString(),
            'reason' => $row->reason, 'revoked_at' => $row->revoked_at?->toIso8601String(),
            'in_force' => (bool) ($row->revoked_at === null && $row->valid_from->toDateString() <= $today && $row->valid_to->toDateString() >= $today),
        ];
    }
}
