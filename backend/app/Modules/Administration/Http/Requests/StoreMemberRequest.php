<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administration.member.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer'],
            'direct_permissions' => ['sometimes', 'array'],
            'direct_permissions.*' => ['string'],
            'reason' => ['required_with:role_ids,direct_permissions', 'nullable', 'string', 'max:1000'],
        ];
    }
}
