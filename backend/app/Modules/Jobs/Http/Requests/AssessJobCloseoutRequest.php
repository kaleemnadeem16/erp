<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

final class AssessJobCloseoutRequest extends JobOperationRequest
{
    public function operation(): string
    {
        return 'ASSESS_CLOSEOUT';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_generation' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'request_key' => ['required', 'string', 'max:85', 'regex:/\S/u'],
        ];
    }
}
