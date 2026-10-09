<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Lead;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

class UpdateLeadRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::LEAD_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enquirer_name' => ['sometimes', 'string', 'max:200'],
            'enquirer_organisation' => ['sometimes', 'nullable', 'string', 'max:200'],
            'enquirer_email' => ['sometimes', 'nullable', 'string', 'max:200'],
            'enquirer_phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
