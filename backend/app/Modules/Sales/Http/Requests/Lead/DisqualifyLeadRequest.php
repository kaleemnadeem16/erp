<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Lead;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

class DisqualifyLeadRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::LEAD_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'request_key' => ['required', 'string', 'max:85'],
            // The newest decision the caller saw on this enquiry (its `decision_event_id`); null when it has never been decided.
            'expected_decision_event_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }
}
