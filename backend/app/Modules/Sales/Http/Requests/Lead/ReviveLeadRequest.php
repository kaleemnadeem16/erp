<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Lead;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

class ReviveLeadRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::LEAD_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
