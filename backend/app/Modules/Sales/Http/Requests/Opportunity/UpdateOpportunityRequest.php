<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Opportunity;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

class UpdateOpportunityRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::OPPORTUNITY_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'party_contact_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expected_amount' => ['sometimes', 'nullable', 'decimal:0,4'],
            'expected_currency_id' => ['sometimes', 'nullable', 'integer', 'exists:currencies,id'],
            'expected_close_on' => ['sometimes', 'nullable', 'date'],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:255'],
            'next_action_on' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
