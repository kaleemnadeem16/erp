<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

use Illuminate\Validation\Rule;

/**
 * Appends one textual observation to a case the caller last saw at `expected_generation`: `sales.customer-decision.evidence.record`. There is no acceptance, consent or amount
 * field. Unknown stays unknown: the occurrence time is sent only with a precision that needs it (the database refuses anything else and the Action reports it as INVALID_INPUT).
 */
final class AppendCustomerDecisionEvidenceRequest extends CustomerDecisionRequest
{
    public const SOURCE_KINDS = ['PURCHASE_ORDER', 'SIGNATURE', 'EMAIL', 'VERBAL', 'OTHER_WRITTEN'];

    public const AUTHORITY_BASES = ['UNVERIFIED', 'STATED_BY_CUSTOMER', 'DOCUMENT_ON_FILE', 'CONFIRMED_BY_SELLER'];

    public const PRECISIONS = ['UNKNOWN', 'DATE', 'INSTANT', 'INTERVAL'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_generation' => ['required', 'integer', 'min:0'],
            'predecessor_id' => ['nullable', 'integer', 'min:1'],
            'source_kind' => ['required', Rule::in(self::SOURCE_KINDS)],
            'authority_verification_basis' => ['required', Rule::in(self::AUTHORITY_BASES)],
            'occurrence_precision' => ['required', Rule::in(self::PRECISIONS)],
            'factual_description' => ['required', 'string', 'min:1', 'max:4000'],
            'external_reference' => ['nullable', 'string', 'max:200'],
            'source_version' => ['nullable', 'string', 'max:80'],
            'customer_representative_snapshot' => ['nullable', 'string', 'max:500'],
            'occurred_at' => ['nullable', 'date'],
            'occurred_until' => ['nullable', 'date'],
            'source_timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
        ];
    }
}
