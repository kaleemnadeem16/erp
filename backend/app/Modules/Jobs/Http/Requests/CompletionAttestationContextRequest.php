<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

final class CompletionAttestationContextRequest extends JobOperationRequest
{
    public function operation(): string
    {
        return 'COMPLETE';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
