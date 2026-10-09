<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Opportunity;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

class ChangeOpportunityStageRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::OPPORTUNITY_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['stage_id' => ['required', 'integer', 'min:1']];
    }
}
