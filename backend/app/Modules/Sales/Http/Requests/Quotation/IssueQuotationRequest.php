<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Support\PermissionVocabulary;

/** Issuing a draft: the issue duty with the independent view (both STAGED), as `IssueQuotation` requires. The reviewed generation travels in the body; the Idempotency-Key header is the request key. */
class IssueQuotationRequest extends QuotationRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_ISSUE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_generation' => ['required', 'integer', 'min:1']];
    }
}
