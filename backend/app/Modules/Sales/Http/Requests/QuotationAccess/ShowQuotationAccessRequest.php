<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\QuotationAccess;

use App\Modules\Sales\Support\PermissionVocabulary as Duty;

class ShowQuotationAccessRequest extends QuotationAccessRequest
{
    protected function duties(): array
    {
        return [Duty::QUOTATION_VIEW];
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['history_per_page' => ['sometimes', 'integer', 'min:1', 'max:50'], 'ownership_before' => ['nullable', 'integer', 'min:1'], 'shares_before' => ['nullable', 'integer', 'min:1']];
    }
}
