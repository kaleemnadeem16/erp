<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

use Illuminate\Support\Carbon;

abstract class JobCommandRequest extends JobOperationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_generation' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'request_key' => ['required', 'string', 'max:85', 'regex:/\S/u'],
            'command_identity' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/'],
            'occurred_at' => $this->timestampRules(),
            'occurrence_precision' => ['sometimes', 'required', 'string', 'max:20', 'regex:/\S/u'],
        ];
    }

    /** @return list<string> */
    protected function timestampRules(): array
    {
        return ['sometimes', 'nullable', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/'];
    }

    /** @return list<string> */
    protected function textRules(int $maximum = 1000, bool $required = false): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'max:'.$maximum, 'regex:/\S/u'];
    }

    public function timestamp(string $key): ?Carbon
    {
        $value = $this->validated($key);

        return is_string($value) ? Carbon::parse($value)->utc() : null;
    }
}
