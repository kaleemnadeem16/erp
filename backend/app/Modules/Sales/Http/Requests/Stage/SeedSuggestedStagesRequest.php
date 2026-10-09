<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Stage;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

class SeedSuggestedStagesRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::STAGE_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
