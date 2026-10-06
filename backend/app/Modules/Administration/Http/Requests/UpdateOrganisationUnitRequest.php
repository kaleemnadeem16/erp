<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Requests;

use App\Modules\Administration\Models\OrganisationUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganisationUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administration.organisation.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9._-]*$/i'],
            'name' => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', Rule::in([OrganisationUnit::ACTIVE, OrganisationUnit::ARCHIVED])],
        ];
    }
}
