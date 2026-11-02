<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Support\PermissionVocabulary;

/** Read-only issue readiness: the preparer duty with the independent view (both STAGED), as `AssessQuotationIssueReadiness` requires. */
class AssessQuotationIssueReadinessRequest extends QuotationRequest
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
