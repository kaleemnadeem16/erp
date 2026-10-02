<?php

declare(strict_types=1);

namespace App\Modules\Company\Http\Requests;

use App\Modules\Company\Support\CompanyField;
use App\Modules\Company\Support\CompanyFields;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates and authorises from the field registry: each field needs the permission its definition names. */
class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        foreach (CompanyFields::all() as $field) {
            if ($this->has($field->key) && ! $user->can($field->permission)) {
                return false;
            }
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => strtoupper(trim($this->input('country_code')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];
        $governed = [];
        foreach (CompanyFields::all() as $field) {
            $rules[$field->key] = $this->rulesFor($field);
            if ($field->governed) {
                $governed[] = $field->key;
            }
        }
        $rules['reason'] = ['required_with:'.implode(',', $governed), 'nullable', 'string', 'max:1000'];

        return $rules;
    }

    /** @return list<mixed> */
    private function rulesFor(CompanyField $field): array
    {
        $rules = ['sometimes', $field->nullable ? 'nullable' : 'required', ...$field->rules];
        if ($field->key === 'code') {
            $rules[] = Rule::unique('companies', 'code')->ignore(app(CompanyContext::class)->id());
        }

        return $rules;
    }
}
