<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Opportunity;

use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Correct one recorded owner fact of a closed opportunity. Needs the dedicated duty and an independent right to read, each by its own scope (ADR 0114). */
class StoreOpportunityAttributionCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return SalesAccess::mayCorrectAttribution($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target_event_id' => ['required', 'integer', 'min:1'],
            'target_field' => ['required', 'string', Rule::in(['OWNER_ID', 'TO_OWNER_ID'])],
            'asserted_owner_id' => ['required', 'integer', 'min:1'],
            // The current correction of this fact the caller saw (null when it has never been corrected): another correction in between is reviewed.
            'expected_predecessor_id' => ['present', 'nullable', 'integer', 'min:1'],
            // The closure the caller saw: a reopen and a new closure in between is reviewed.
            'expected_closure_event_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
            'request_key' => ['required', 'string', 'max:85'],
        ];
    }
}
