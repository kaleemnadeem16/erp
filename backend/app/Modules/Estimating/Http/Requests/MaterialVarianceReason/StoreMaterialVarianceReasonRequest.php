<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialVarianceReason;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialVarianceReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.material_variance_policy.manage') === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtolower(trim($this->input('code')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/', CompanyScoped::unique('material_variance_reasons', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
