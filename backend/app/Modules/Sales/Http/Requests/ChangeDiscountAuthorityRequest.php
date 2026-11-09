<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

class ChangeDiscountAuthorityRequest extends DiscountAuthorityRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->writeRules();
    }
}
