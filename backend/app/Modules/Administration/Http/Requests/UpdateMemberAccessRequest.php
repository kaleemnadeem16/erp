<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
