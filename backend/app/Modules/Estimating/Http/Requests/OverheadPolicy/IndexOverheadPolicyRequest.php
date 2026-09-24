<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\OverheadPolicy;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Estimating\Support\OverheadPolicyStatus;
use App\Modules\Estimating\Support\OverheadScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Every field here — reason, changed_by, DRAFT/SUPERSEDED rows — is audit
 * detail, so listing is gated the same as InventoryPolicyChangeController's
 * history endpoint, not left open like the rest of Estimating's index
 * routes. ResolveOverheadPolicyRequest is the unrestricted "what applies
 * right now" read this is deliberately not.
 */
class IndexOverheadPolicyRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return $this->user()?->can('estimating.policy-history.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:scope_type,scope_id,status'],
            'filter.scope_type' => ['sometimes', Rule::enum(OverheadScopeType::class)],
            'filter.scope_id' => ['sometimes', 'integer'],
            'filter.status' => ['sometimes', Rule::enum(OverheadPolicyStatus::class)],
        ], $this->listQueryRules(['effective_from', 'created_at'], ['changedBy']));
    }
}
