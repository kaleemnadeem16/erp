<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\StockMixPolicy;

use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\StockMixLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockMixPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.stock_mix_policy.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Company-wide only for now (ADR 0076). The storage and resolver already cover category and item scope, so
            // allowing them later is a change to this one list.
            'scope_type' => ['required', Rule::in([MaterialSelectionScope::Company->value])],
            'scope_id' => ['prohibited'],
            // Present but null records "no preference here"; the field is required so a forgotten level is never read as one.
            'level' => ['present', 'nullable', Rule::enum(StockMixLevel::class)],
            'threshold_percent' => ['nullable', 'decimal:0,4', 'gt:0', 'max:100000'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['scope_type.in' => 'For now this can only be set for the whole company.'];
    }
}
