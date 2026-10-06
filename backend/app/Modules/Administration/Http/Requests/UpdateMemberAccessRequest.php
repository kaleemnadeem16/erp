<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Requests;

use App\Modules\Administration\Models\AccessScopeLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administration.access.assign') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role_ids' => ['present', 'array'], 'role_ids.*' => ['integer'],
            'direct_permissions' => ['present', 'array'], 'direct_permissions.*' => ['string'],
            // "Only my units" on an assignment (ADR 0089): each entry names exactly one role or one direct permission. Omitted keeps the current limits.
            'scope_limits' => ['sometimes', 'array'],
            'scope_limits.*.role_id' => ['nullable', 'integer'],
            'scope_limits.*.permission' => ['nullable', 'string'],
            'scope_limits.*.scope' => ['required', Rule::in([AccessScopeLimit::MY_UNITS])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
