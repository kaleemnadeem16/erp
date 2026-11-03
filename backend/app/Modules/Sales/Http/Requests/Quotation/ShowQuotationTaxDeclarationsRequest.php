<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Support\PermissionVocabulary;

/** The declaration history of a draft: prepare with the independent view, both STAGED. */
class ShowQuotationTaxDeclarationsRequest extends QuotationRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_PREPARE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
