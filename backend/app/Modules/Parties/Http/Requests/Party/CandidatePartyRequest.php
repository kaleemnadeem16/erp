<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\Party;

use App\Modules\Parties\Support\PartyCandidateAccess;
use Illuminate\Foundation\Http\FormRequest;

/** Probe for an existing identity. Guarded again in FindPartyCandidates. */
class CandidatePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && PartyCandidateAccess::mayAsk($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'registration_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
