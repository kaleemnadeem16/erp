<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialVariancePolicy;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Estimating\Support\MaterialVariancePolicyStatus;
use App\Modules\Estimating\Support\VarianceScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Reason, changed_by and superseded versions are audit detail, so listing is gated like overhead-policy history. */
class IndexMaterialVariancePolicyRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return $this->user()?->canAny(['estimating.policy-history.view', 'estimating.material_variance_policy.manage']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:scope_type,scope_id,status'],
            'filter.scope_type' => ['sometimes', Rule::enum(VarianceScopeType::class)],
            'filter.scope_id' => ['sometimes', 'integer'],
            'filter.status' => ['sometimes', Rule::enum(MaterialVariancePolicyStatus::class)],
        ], $this->listQueryRules(['effective_from', 'created_at'], ['changedBy', 'bands']));
    }
}
