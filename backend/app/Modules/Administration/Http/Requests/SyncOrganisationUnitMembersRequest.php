<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncOrganisationUnitMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administration.organisation.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['user_ids' => ['present', 'array'], 'user_ids.*' => ['integer']];
    }
}
