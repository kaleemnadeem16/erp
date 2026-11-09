<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Validation\Rule;

class ChangeDiscountAuthorityModeRequest extends DiscountAuthorityRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->writeRules() + ['mode' => ['required', Rule::in(['OFF', 'SHADOW', 'ENFORCE'])]];
    }
}
