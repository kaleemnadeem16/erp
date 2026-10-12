<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\OpportunityAttributionCorrection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OpportunityAttributionCorrection */
class OpportunityAttributionCorrectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OpportunityAttributionCorrection $correction */
        $correction = $this->resource;

        return [
            'id' => $correction->id,
            'target_event_id' => $correction->target_event_id,
            'target_field' => $correction->target_field,
            'predecessor_id' => $correction->predecessor_id,
            'recorded_owner_id' => $correction->recorded_owner_id,
            'recorded_owner_name' => $correction->relationLoaded('recordedOwner') ? $correction->recordedOwner?->name : null,
            'asserted_owner_id' => $correction->asserted_owner_id,
            'asserted_owner_name' => $correction->relationLoaded('assertedOwner') ? $correction->assertedOwner?->name : null,
            'actor_id' => $correction->actor_id,
            'actor_name' => $correction->relationLoaded('actor') ? $correction->actor?->name : null,
            'recorded_at' => $correction->recorded_at->toIso8601String(),
            'reason' => $correction->reason,
        ];
    }
}
