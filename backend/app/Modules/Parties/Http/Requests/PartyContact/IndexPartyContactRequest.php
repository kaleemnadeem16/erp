<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\PartyContact;

use App\Modules\Parties\Support\PartyCandidateAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Contacts are people's names, phones and emails, so reading them is narrower than reading a Party: it needs a duty that already involves choosing or
 * maintaining a Party (Party maintenance, or a Sales duty that registers itself with PartyCandidateAccess), never just being signed in.
 */
class IndexPartyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && PartyCandidateAccess::mayAsk($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:search'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
