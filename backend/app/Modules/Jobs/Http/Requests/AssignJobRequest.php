<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

final class AssignJobRequest extends JobCommandRequest
{
    public function operation(): string
    {
        return 'ASSIGN';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'assignee_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'reason' => $this->textRules(),
            'instructions' => $this->textRules(2000),
            'intended_from' => ['nullable', 'required_with:intended_until', ...array_diff($this->timestampRules(), ['sometimes', 'nullable'])],
            'intended_until' => [...$this->timestampRules(), 'after_or_equal:intended_from'],
        ];
    }
}
