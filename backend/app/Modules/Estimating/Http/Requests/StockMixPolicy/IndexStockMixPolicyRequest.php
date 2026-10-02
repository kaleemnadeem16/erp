<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\StockMixPolicy;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Estimating\Support\MaterialSelectionPolicyStatus;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The stored hand-chosen-stock controls and their history; reason and actor are audit detail (ADR 0076). */
class IndexStockMixPolicyRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return $this->user()?->canAny(['estimating.policy-history.view', 'estimating.stock_mix_policy.manage']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:scope_type,scope_id,status'],
            'filter.scope_type' => ['sometimes', Rule::enum(MaterialSelectionScope::class)],
            'filter.scope_id' => ['sometimes', 'integer'],
            'filter.status' => ['sometimes', Rule::enum(MaterialSelectionPolicyStatus::class)],
        ], $this->listQueryRules(['effective_from', 'created_at'], ['changedBy']));
    }
}
