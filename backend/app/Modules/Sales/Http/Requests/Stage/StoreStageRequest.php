<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Stage;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

class StoreStageRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::STAGE_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:100'], 'sort_order' => ['sometimes', 'integer', 'min:0']];
    }
}
