<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

final class CloseJobRequest extends JobCommandRequest
{
    public function operation(): string
    {
        return 'CLOSE';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'assessment_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'statement' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
