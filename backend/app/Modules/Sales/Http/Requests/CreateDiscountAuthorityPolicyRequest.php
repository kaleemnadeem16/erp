<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Support\DiscountAuthority\DiscountPolicyInput;

class CreateDiscountAuthorityPolicyRequest extends DiscountAuthorityRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->writeRules() + DiscountPolicyInput::rules();
    }
}
