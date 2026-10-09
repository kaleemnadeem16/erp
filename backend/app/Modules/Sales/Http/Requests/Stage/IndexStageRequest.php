<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Stage;

use App\Modules\Sales\Http\Requests\SalesRequest;

class IndexStageRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
