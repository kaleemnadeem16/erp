<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\LeadIntake;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeadIntake */
class LeadIntakeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LeadIntake $intake */
        $intake = $this->resource;

        return [
            'id' => $intake->id,
            'evidence_kind' => $intake->evidence_kind,
            'captured_at' => $intake->captured_at->toIso8601String(),
            'captured_by' => $intake->captured_by,
            'capture_reference' => $intake->capture_reference,
            'received_at' => $intake->received_at->toIso8601String(),
            'recorded_creator_id' => $intake->recorded_creator_id,
            'enquirer_name' => $intake->enquirer_name,
            'enquirer_organisation' => $intake->enquirer_organisation,
            'enquirer_email' => $intake->enquirer_email,
            'enquirer_phone' => $intake->enquirer_phone,
            'description' => $intake->description,
            'source' => $intake->source,
        ];
    }
}
