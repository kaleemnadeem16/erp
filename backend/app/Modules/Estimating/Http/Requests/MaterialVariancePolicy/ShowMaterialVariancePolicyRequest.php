<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialVariancePolicy;

use App\Http\Requests\Concerns\ValidatesIncludes;
use Illuminate\Foundation\Http\FormRequest;

class ShowMaterialVariancePolicyRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return $this->user()?->canAny(['estimating.policy-history.view', 'estimating.material_variance_policy.manage']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['changedBy']);
    }
}
