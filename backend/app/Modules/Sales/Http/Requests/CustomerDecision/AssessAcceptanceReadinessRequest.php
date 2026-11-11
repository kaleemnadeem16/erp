<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

final class AssessAcceptanceReadinessRequest extends ShowCustomerAcceptanceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['path' => ['sometimes', 'string', 'max:30'], 'location_kind' => ['sometimes', 'string', 'in:UNKNOWN,REMOTE,ON_SITE'],
            'contact_id' => ['nullable', 'integer', 'min:1'], 'site_id' => ['nullable', 'integer', 'min:1'], 'instructions' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
