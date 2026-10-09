<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Opportunity;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

class ReopenOpportunityRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::OPPORTUNITY_CLOSE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
