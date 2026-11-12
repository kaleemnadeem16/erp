<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

use App\Http\Requests\Concerns\ValidatesListQuery;

/** The case list: `sales.customer-decision.view`. */
final class ListCustomerDecisionCasesRequest extends CustomerDecisionRequest
{
    use ValidatesListQuery;

    protected function viewing(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:party_id,search,recorded_from,recorded_to,quotation_family_id'],
            'filter.party_id' => ['sometimes', 'integer', 'min:1'],
            'filter.search' => ['sometimes', 'string', 'max:120'],
            // Inclusive calendar days of the COMPANY time zone (ADR 0079 §8), applied to when the case was opened.
            'filter.recorded_from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.recorded_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:filter.recorded_from'],
            'filter.quotation_family_id' => ['sometimes', 'integer', 'min:1'],
        ] + $this->listQueryRules(['id'], []);
    }
}
