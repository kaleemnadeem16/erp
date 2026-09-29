<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialVarianceReason;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The reason list is what a preview user picks from, so it is readable by anyone who can run a
 * preview as well as by whoever manages it. It carries no cost, stock or policy detail.
 */
class IndexMaterialVarianceReasonRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return $this->user()?->canAny(['estimating.material_preview.run', 'estimating.material_variance_policy.manage']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:active,search'],
            'filter.active' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['code', 'name', 'created_at'], []));
    }
}
