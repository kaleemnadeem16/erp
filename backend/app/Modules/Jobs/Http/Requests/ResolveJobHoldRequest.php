<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

final class ResolveJobHoldRequest extends JobCommandRequest
{
    public function operation(): string
    {
        return 'RESOLVE_HOLD';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'hold_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'reason' => $this->textRules(required: true),
        ];
    }
}
