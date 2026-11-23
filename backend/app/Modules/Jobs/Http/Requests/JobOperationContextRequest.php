<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use Illuminate\Validation\Rule;

final class JobOperationContextRequest extends JobOperationRequest
{
    public function operation(): string
    {
        $operation = $this->input('operation');

        return is_string($operation) ? $operation : '';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['operation' => ['required', 'string', Rule::in(array_keys(JobLifecycleOperation::DUTIES))]];
    }
}
