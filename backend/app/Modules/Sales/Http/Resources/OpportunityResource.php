<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Parties\Http\Resources\PartyResource;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Support\OpportunityOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Opportunity */
class OpportunityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Opportunity $opportunity */
        $opportunity = $this->resource;
        $owner = $opportunity->relationLoaded('owner') ? $opportunity->owner : null;

        return [
            'id' => $opportunity->id,
            'number' => $opportunity->number,
            'title' => $opportunity->title,
            'description' => $opportunity->description,
            'party_id' => $opportunity->party_id,
            'party_contact_id' => $opportunity->party_contact_id,
            'lead_id' => $opportunity->lead_id,
            'source' => $opportunity->source,
            'owner_id' => $opportunity->owner_id,
            'owner_name' => $owner?->name,
            // The owner is no longer an active member: the deal is flagged for reassignment, never silently changed (ADR 0106).
            'owner_needs_reassignment' => $owner !== null && $opportunity->outcome === OpportunityOutcome::Open && $owner->status !== MemberStatus::Active,
            'stage_id' => $opportunity->stage_id,
            'outcome' => $opportunity->outcome->value,
            'expected_amount' => $opportunity->expected_amount,
            'expected_currency_id' => $opportunity->expected_currency_id,
            'expected_close_on' => $opportunity->expected_close_on?->toDateString(),
            'next_action' => $opportunity->next_action,
            'next_action_on' => $opportunity->next_action_on?->toDateString(),
            'closed_at' => $opportunity->closed_at?->toIso8601String(),
            'closed_by' => $opportunity->closed_by,
            'closure_reason' => $opportunity->closure_reason,
            'created_by' => $opportunity->created_by,
            'party' => $this->whenLoaded('party', fn () => new PartyResource($opportunity->party)),
            'stage' => $this->whenLoaded('stage', fn () => new SalesStageResource($opportunity->stage)),
            'lead' => $this->whenLoaded('lead', fn () => $opportunity->lead === null ? null : new LeadResource($opportunity->lead)),
            'notes' => SalesNoteResource::collection($this->whenLoaded('notes')),
            'history' => SalesHistoryResource::collection($this->whenLoaded('history')),
            'created_at' => $opportunity->created_at?->toIso8601String(),
            'updated_at' => $opportunity->updated_at?->toIso8601String(),
        ];
    }
}
