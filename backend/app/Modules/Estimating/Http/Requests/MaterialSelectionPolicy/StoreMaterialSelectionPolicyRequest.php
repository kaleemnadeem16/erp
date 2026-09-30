<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialSelectionPolicy;

use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialSelectionPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.selection-policy.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope_type' => ['required', Rule::enum(MaterialSelectionScope::class)],
            'scope_id' => [
                'prohibited_if:scope_type,'.MaterialSelectionScope::Company->value,
                'required_unless:scope_type,'.MaterialSelectionScope::Company->value,
                'integer',
            ],
            // Present but null clears the setting ("no preference here"); the field itself is required so a
            // forgotten strategy is never read as a clear.
            'strategy' => ['present', 'nullable', Rule::enum(MaterialSelectionStrategy::class)],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
