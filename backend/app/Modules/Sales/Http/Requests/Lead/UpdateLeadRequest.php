<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Lead;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Validation\Validator;

class UpdateLeadRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::LEAD_MANAGE;
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->hasAny(['enquirer_name', 'enquirer_organisation', 'enquirer_email', 'enquirer_phone', 'description', 'source'])) {
                $validator->errors()->add('enquirer_name', 'Give at least one enquiry detail to correct.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enquirer_name' => ['sometimes', 'string', 'max:200'],
            'enquirer_organisation' => ['sometimes', 'nullable', 'string', 'max:200'],
            'enquirer_email' => ['sometimes', 'nullable', 'string', 'max:200'],
            'enquirer_phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:1000'],
            'request_key' => ['required', 'string', 'max:85'],
            // The enquiry version the caller saw (the lead's `enquiry_version`); another correction in between is reviewed, never merged.
            'expected_enquiry_version' => ['required', 'integer', 'min:0'],
            // A correction changes the six enquiry details and nothing else.
            'status' => ['prohibited'],
            'party_id' => ['prohibited'],
            'assigned_to' => ['prohibited'],
            'received_at' => ['prohibited'],
            'number' => ['prohibited'],
        ];
    }
}
