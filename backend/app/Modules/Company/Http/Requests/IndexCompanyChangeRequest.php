<?php

declare(strict_types=1);

namespace App\Modules\Company\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexCompanyChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('company.history.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'between:1,100'], 'filter.section' => ['sometimes', 'in:GENERAL,LOCALIZATION,REGISTRATION,CONTACT']];
    }
}
