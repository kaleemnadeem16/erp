<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\SalesAccess;

/** The inactive sensitive duty and an independent decision read; quotation record policy is repeated by the Action under its fence. */
class PreviewCustomerAcceptanceRequest extends CustomerDecisionRequest
{
    public function authorize(): bool
    {
        return SalesAccess::mayAll($this->user(), P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW);
    }

    protected function capability(): string
    {
        return P::CUSTOMER_DECISION_ACCEPT;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['component_id' => ['required', 'integer', 'min:1'], 'evidence_id' => ['required', 'integer', 'min:1'], 'issued_line_id' => ['required', 'integer', 'min:1'],
            'expected_case_generation' => ['required', 'integer', 'min:0'], 'expected_scope_generation' => ['required', 'integer', 'min:0'], 'expected_choice_generation' => ['nullable', 'integer', 'min:0']];
    }
}
