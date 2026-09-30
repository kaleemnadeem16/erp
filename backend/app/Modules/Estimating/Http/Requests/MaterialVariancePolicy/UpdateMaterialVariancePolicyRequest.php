<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialVariancePolicy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Scope is deliberately absent: a policy does not change what it governs. */
class UpdateMaterialVariancePolicyRequest extends FormRequest
{
    use ValidatesVarianceBands;

    public function authorize(): bool
    {
        return $this->user()?->can('estimating.material_variance_policy.manage') === true;
    }

    public function messages(): array
    {
        return $this->bandMessages();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'minimum_effect' => ['sometimes', 'nullable', Rule::in($this->effectValues()), $this->approvalAvailabilityRule()],
            'reason' => ['sometimes', 'string', 'max:1000'],
        ], $this->bandRules());
    }
}
