<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Opportunity;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

class ReassignOpportunityRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::OPPORTUNITY_ASSIGN;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['owner_id' => ['required', 'integer', 'min:1'], 'reason' => ['sometimes', 'nullable', 'string', 'max:1000']];
    }
}
