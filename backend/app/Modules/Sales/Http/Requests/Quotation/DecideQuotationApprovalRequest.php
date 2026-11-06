<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Validation\Rule;

/** Approving or rejecting a draft's pending approval: the approve duty with the independent view (both STAGED), as `DecideQuotationApproval` requires. A rejection needs a reason. */
class DecideQuotationApprovalRequest extends QuotationRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_APPROVE;
    }

    protected function extraDuties(): array
    {
        return [PermissionVocabulary::QUOTATION_VIEW];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_generation' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'string', Rule::in(['APPROVED', 'REJECTED'])],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:decision,REJECTED'],
        ];
    }
}
