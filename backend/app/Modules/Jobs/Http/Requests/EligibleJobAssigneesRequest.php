<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

final class EligibleJobAssigneesRequest extends JobOperationRequest
{
    public function operation(): string
    {
        return 'ASSIGN';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['required', 'string', 'min:2', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
