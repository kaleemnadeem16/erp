<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\PartyContact;

use App\Modules\Parties\Support\PermissionVocabulary;
use Illuminate\Foundation\Http\FormRequest;

/** Which Party a contact belongs to never changes: repointing is not offered. */
class UpdatePartyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PermissionVocabulary::PARTY_MANAGE) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_primary' => ['sometimes', 'boolean'],
            'party_id' => ['prohibited'],
        ];
    }
}
