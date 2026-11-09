<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Validation\Rule;

class AssignDiscountAuthorityRequest extends DiscountAuthorityRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->writeRules() + ['user_id' => ['required', 'integer', 'min:1'], 'band_id' => ['required', 'integer', 'min:1'],
            'role_id' => ['nullable', 'integer', 'min:1'], 'unit_id' => ['nullable', 'integer', 'min:1'],
            'purpose' => ['required', Rule::in(['ROUTINE', 'APPROVE', 'BOTH'])],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from']];
    }
}
