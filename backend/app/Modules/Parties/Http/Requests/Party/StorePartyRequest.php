<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\Party;

use App\Modules\Parties\Models\Party;
use App\Rules\Support\CompanyScoped;
use App\Rules\Support\ValidCustomFields;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('parties.party.manage') === true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('custom_fields')) {
            $this->merge(['custom_fields' => []]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', CompanyScoped::unique('parties', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'registration_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'custom_fields' => ['sometimes', new ValidCustomFields(Party::customFieldEntityType())],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
