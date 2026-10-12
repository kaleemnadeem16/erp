<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Opportunity;

use App\Http\Requests\Concerns\ValidatesIncludes;
use App\Modules\Sales\Http\Requests\SalesRequest;

class ShowOpportunityRequest extends SalesRequest
{
    use ValidatesIncludes;

    protected function capability(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['party', 'owner', 'stage', 'lead', 'notes', 'history', 'attribution_corrections']);
    }
}
