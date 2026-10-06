<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Reading the organisation tree: those who maintain it, and those who administer access (they assign people to it). */
class IndexOrganisationUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['administration.organisation.view', 'administration.organisation.manage']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
