<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\QuotationAccess;

use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Validation\Rule;

class GrantQuotationAccessRequest extends QuotationAccessRequest
{
    protected function duties(): array
    {
        $duties = [Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_VIEW];
        if ($this->input('level') === 'PREPARE') {
            $duties[] = Duty::QUOTATION_PREPARE;
        }

        return array_values(array_unique($duties));
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return $this->writeRules() + ['recipient_id' => ['required', 'integer', 'min:1'], 'level' => ['required', Rule::in(['VIEW', 'PREPARE'])], 'starts_at' => ['nullable', 'string', 'max:40'], 'ends_at' => ['nullable', 'string', 'max:40']];
    }
}
