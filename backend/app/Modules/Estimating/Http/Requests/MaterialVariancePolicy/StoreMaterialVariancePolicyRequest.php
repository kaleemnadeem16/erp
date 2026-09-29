<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialVariancePolicy;

use App\Modules\Estimating\Support\VarianceScopeType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialVariancePolicyRequest extends FormRequest
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
        $scopeType = $this->input('scope_type');

        return array_merge([
            'scope_type' => ['required', Rule::enum(VarianceScopeType::class)],
            'scope_id' => [
                'prohibited_if:scope_type,'.VarianceScopeType::Company->value,
                'required_unless:scope_type,'.VarianceScopeType::Company->value,
                'integer',
                // Category or item depending on scope_type, so no single exists() rule; the models carry the company scope.
                function (string $attribute, mixed $value, Closure $fail) use ($scopeType): void {
                    if ($value === null) {
                        return;
                    }
                    if ($scopeType === VarianceScopeType::Category->value && ! Category::query()->whereKey($value)->exists()) {
                        $fail('The selected category does not exist.');
                    }
                    if ($scopeType === VarianceScopeType::Item->value && ! Item::query()->whereKey($value)->exists()) {
                        $fail('The selected item does not exist.');
                    }
                },
            ],
            'minimum_effect' => ['sometimes', 'nullable', Rule::in($this->configurableEffects())],
            'reason' => ['required', 'string', 'max:1000'],
        ], $this->bandRules());
    }
}
