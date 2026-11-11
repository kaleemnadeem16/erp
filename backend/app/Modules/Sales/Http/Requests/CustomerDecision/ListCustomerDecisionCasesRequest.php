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
            'filter' => ['sometimes', 'array:party_id,search'],
            'filter.party_id' => ['sometimes', 'integer', 'min:1'],
            'filter.search' => ['sometimes', 'string', 'max:120'],
        ] + $this->listQueryRules(['id'], []);
    }
}
