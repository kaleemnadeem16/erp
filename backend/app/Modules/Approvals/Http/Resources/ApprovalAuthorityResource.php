<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Resources;

use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Numbering\Services\CompanyClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApprovalAuthority */
class ApprovalAuthorityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ApprovalAuthority $row */
        $row = $this->resource;

        // Validity is a business date: compare it with today's date in the company's time zone, as date strings.
        $today = app(CompanyClock::class)->today()->toDateString();

        return [
            'id' => $row->id, 'user_id' => $row->user_id, 'subject_type' => $row->subject_type,
            'unlimited' => $row->unlimited, 'ceiling_base_amount' => $row->ceiling_base_amount,
            'valid_from' => $row->valid_from?->toDateString(), 'valid_to' => $row->valid_to?->toDateString(),
            'reason' => $row->reason, 'revoked_at' => $row->revoked_at?->toIso8601String(),
            'in_force' => (bool) ($row->revoked_at === null && ($row->valid_from === null || $row->valid_from->toDateString() <= $today) && ($row->valid_to === null || $row->valid_to->toDateString() >= $today)),
        ];
    }
}
