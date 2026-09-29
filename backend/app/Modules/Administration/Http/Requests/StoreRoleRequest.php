<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administration.role.manage') === true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'permissions' => ['present', 'array'], 'permissions.*' => ['string'], 'reason' => ['required', 'string', 'max:1000']];
    }
}
