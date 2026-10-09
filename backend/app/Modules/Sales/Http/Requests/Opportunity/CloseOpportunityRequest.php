<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Opportunity;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Validation\Rule;

class CloseOpportunityRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::OPPORTUNITY_CLOSE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['outcome' => ['required', Rule::in(['LOST', 'ABANDONED', 'WON'])], 'reason' => ['required', 'string', 'max:1000']];
    }
}
