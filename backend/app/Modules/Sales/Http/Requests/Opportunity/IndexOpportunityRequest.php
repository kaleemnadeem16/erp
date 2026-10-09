<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Opportunity;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\OpportunityOutcome;
use Illuminate\Validation\Rule;

class IndexOpportunityRequest extends SalesRequest
{
    use ValidatesListQuery;

    protected function capability(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:outcome,stage_id,owner_id,party_id,search,needs_attention,mine'],
            'filter.outcome' => ['sometimes', Rule::enum(OpportunityOutcome::class)],
            'filter.stage_id' => ['sometimes', 'integer', 'min:1'],
            'filter.owner_id' => ['sometimes', 'integer', 'min:1'],
            'filter.party_id' => ['sometimes', 'integer', 'min:1'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.needs_attention' => ['sometimes', 'boolean'],
            'filter.mine' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['created_at', '-created_at', 'number', '-number', 'title', '-title', 'expected_close_on', '-expected_close_on', 'next_action_on', '-next_action_on'])],
            'include' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
