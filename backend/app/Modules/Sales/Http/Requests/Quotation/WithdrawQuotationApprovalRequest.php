<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Support\PermissionVocabulary;

/** Taking back a pending approval request: prepare with the independent view (both STAGED), as `WithdrawQuotationApproval` requires. A reason is required. */
class WithdrawQuotationApprovalRequest extends QuotationRequest
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
        return ['expected_generation' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']];
    }
}
