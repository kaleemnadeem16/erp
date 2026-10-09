<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Parties\Http\Resources\PartyResource;
use App\Modules\Sales\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lead */
class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Lead $lead */
        $lead = $this->resource;

        return [
            'id' => $lead->id,
            'number' => $lead->number,
            'status' => $lead->status->value,
            'enquirer_name' => $lead->enquirer_name,
            'enquirer_organisation' => $lead->enquirer_organisation,
            'enquirer_email' => $lead->enquirer_email,
            'enquirer_phone' => $lead->enquirer_phone,
            'description' => $lead->description,
            'source' => $lead->source,
            'received_at' => $lead->received_at->toIso8601String(),
            'party_id' => $lead->party_id,
            'assigned_to' => $lead->assigned_to,
            'qualified_at' => $lead->qualified_at?->toIso8601String(),
            'disqualified_at' => $lead->disqualified_at?->toIso8601String(),
            'disqualification_reason' => $lead->disqualification_reason,
            'created_by' => $lead->created_by,
            'party' => $this->whenLoaded('party', fn () => $lead->party === null ? null : new PartyResource($lead->party)),
            'opportunities' => OpportunityResource::collection($this->whenLoaded('opportunities')),
            'notes' => SalesNoteResource::collection($this->whenLoaded('notes')),
            'history' => SalesHistoryResource::collection($this->whenLoaded('history')),
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }
}
