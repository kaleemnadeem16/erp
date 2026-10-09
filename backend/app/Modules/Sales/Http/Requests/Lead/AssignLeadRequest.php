<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Lead;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

class AssignLeadRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::OPPORTUNITY_ASSIGN;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['assigned_to' => ['present', 'nullable', 'integer', 'min:1']];
    }
}
