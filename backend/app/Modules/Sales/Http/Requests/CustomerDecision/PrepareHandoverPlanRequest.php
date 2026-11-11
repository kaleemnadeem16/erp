<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

final class PrepareHandoverPlanRequest extends PreviewHandoverRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return parent::rules() + ['preview_token' => ['required', 'string', 'max:8192']];
    }
}
