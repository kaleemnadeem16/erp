<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialVariancePolicy;

use Illuminate\Foundation\Http\FormRequest;

class ShowMaterialVarianceCapabilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['estimating.material_preview.run', 'estimating.material_variance_policy.manage']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
