<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\Party;

use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\PartyKind;
use App\Rules\Support\CompanyScoped;
use App\Rules\Support\ValidCustomFields;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('parties.party.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Nullsafe: Scramble calls rules() outside a real request to infer types,
        // where route('party') is null — see UpdateCategoryRequest for the same note.
        /** @var Party|null $party */
        $party = $this->route('party');

        return [
            'code' => ['sometimes', 'string', 'max:255', CompanyScoped::unique('parties', 'code', $party?->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::enum(PartyKind::class)],
            'registration_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'custom_fields' => ['sometimes', new ValidCustomFields(Party::customFieldEntityType())],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
