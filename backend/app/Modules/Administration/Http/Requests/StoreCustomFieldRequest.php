<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Requests;

use App\Modules\Administration\Support\CustomFieldEntities;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administration.custom-field.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entity_type' => ['required', Rule::in(CustomFieldEntities::TYPES)],
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', CompanyScoped::unique('custom_field_definitions', 'code')->where('entity_type', $this->input('entity_type'))],
            'label' => ['required', 'string', 'max:255'],
            'data_type' => ['required', Rule::in(['string', 'number', 'bool', 'date', 'select'])],
            'options' => ['sometimes', 'array:choices'],
            'options.choices' => ['exclude_unless:data_type,select', 'required_if:data_type,select', 'array', 'min:1', 'max:100'],
            'options.choices.*' => ['required', 'string', 'max:255', 'distinct'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:32767'],
        ];
    }
}
