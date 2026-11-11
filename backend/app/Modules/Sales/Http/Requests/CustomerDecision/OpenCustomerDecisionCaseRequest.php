<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

/** Opens an intake case: `sales.customer-decision.evidence.record`. The Idempotency-Key header is the request key. */
final class OpenCustomerDecisionCaseRequest extends CustomerDecisionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'party_id' => ['required', 'integer', 'min:1'],
            'intake_identity' => ['required', 'string', 'max:120'],
            'components' => ['sometimes', 'array', 'max:50'],
            'components.*' => ['array:intent_identity,family_id,original_scope_anchor_id'],
            'components.*.intent_identity' => ['required', 'string', 'max:120'],
            'components.*.family_id' => ['required', 'integer', 'min:1'],
            'components.*.original_scope_anchor_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
