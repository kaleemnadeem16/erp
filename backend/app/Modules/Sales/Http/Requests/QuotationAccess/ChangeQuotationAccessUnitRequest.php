<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\QuotationAccess;

use App\Modules\Sales\Support\PermissionVocabulary as Duty;

class ChangeQuotationAccessUnitRequest extends QuotationAccessRequest
{
    protected function duties(): array
    {
        $duties = [Duty::QUOTATION_ASSIGN, Duty::QUOTATION_VIEW];

        return array_values(array_unique($duties));
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return $this->writeRules() + ['access_unit_id' => ['present', 'nullable', 'integer', 'min:1']];
    }
}
