<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\SalesHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalesHistory */
class SalesHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SalesHistory $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'event' => $row->event,
            'actor_id' => $row->actor_id,
            'actor_name' => $row->relationLoaded('actor') ? $row->actor?->name : null,
            'occurred_at' => $row->occurred_at->toIso8601String(),
            'reason' => $row->reason,
            /** @var array<string, mixed> */
            'details' => (object) ($row->details ?? []),
        ];
    }
}
