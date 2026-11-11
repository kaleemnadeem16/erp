<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

/** One case with its components and observation chain: `sales.customer-decision.view`. */
final class ShowCustomerDecisionCaseRequest extends CustomerDecisionRequest
{
    protected function viewing(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
