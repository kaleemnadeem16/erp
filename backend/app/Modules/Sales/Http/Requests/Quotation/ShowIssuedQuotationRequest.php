<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Support\PermissionVocabulary;

/** The issued quotation's official number and frozen header terms: the view duty (STAGED); totals only for holders of prepare or issue on that revision (decided in the Action). */
class ShowIssuedQuotationRequest extends QuotationRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_VIEW;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
