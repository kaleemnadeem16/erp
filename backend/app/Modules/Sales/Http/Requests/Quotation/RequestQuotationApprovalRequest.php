<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Support\PermissionVocabulary;

/** Asking for approval of a draft's discount or override: prepare with the independent view (both STAGED), as `RequestQuotationApproval` requires. The reviewed generation travels in the body. */
class RequestQuotationApprovalRequest extends QuotationRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_PREPARE;
    }

    protected function extraDuties(): array
    {
        return [PermissionVocabulary::QUOTATION_VIEW];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_generation' => ['required', 'integer', 'min:1']];
    }
}
