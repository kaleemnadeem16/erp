<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Stage;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Validation\Rule;

class UpdateStageRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::STAGE_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:100'], 'sort_order' => ['sometimes', 'integer', 'min:0'], 'status' => ['sometimes', Rule::in(['ACTIVE', 'ARCHIVED'])]];
    }
}
