<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\LeadEnquiryCorrection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeadEnquiryCorrection */
class LeadEnquiryCorrectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LeadEnquiryCorrection $correction */
        $correction = $this->resource;
        $fields = [];
        foreach ($correction->fields as $field) {
            $fields[] = ['field_name' => $field->field_name, 'old_value' => $field->old_value, 'new_value' => $field->new_value];
        }

        return [
            'id' => $correction->id,
            'actor_id' => $correction->actor_id,
            'recorded_at' => $correction->recorded_at->toIso8601String(),
            'reason' => $correction->reason,
            'previous_version' => $correction->previous_version,
            'resulting_version' => $correction->resulting_version,
            'fields' => $fields,
        ];
    }
}
