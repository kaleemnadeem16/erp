<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialVarianceReason;

use Illuminate\Foundation\Http\FormRequest;

/** `code` is not editable: history refers to a reason by it. Rename it by retiring it and adding another. */
class UpdateMaterialVarianceReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.material_variance_policy.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
