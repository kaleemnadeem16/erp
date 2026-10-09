<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Lead;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\LeadStatus;
use Illuminate\Validation\Rule;

class IndexLeadRequest extends SalesRequest
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
            'filter' => ['sometimes', 'array:status,assigned_to,party_id,search,unassigned'],
            'filter.status' => ['sometimes', Rule::enum(LeadStatus::class)],
            'filter.assigned_to' => ['sometimes', 'integer', 'min:1'],
            'filter.party_id' => ['sometimes', 'integer', 'min:1'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.unassigned' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['received_at', '-received_at', 'number', '-number', 'created_at', '-created_at'])],
            'include' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
