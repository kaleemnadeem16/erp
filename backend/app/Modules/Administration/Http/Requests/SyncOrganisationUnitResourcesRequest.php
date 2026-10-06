<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncOrganisationUnitResourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administration.organisation.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['resources' => ['present', 'array'], 'resources.*.type' => ['required', 'string', 'max:40'], 'resources.*.id' => ['required', 'integer']];
    }
}
