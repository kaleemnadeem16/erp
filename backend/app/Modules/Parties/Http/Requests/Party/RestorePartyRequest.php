<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\Party;

use Illuminate\Foundation\Http\FormRequest;

/** Restoring an archived identity is Party maintenance (ADR 0105). Guarded again in RestoreParty. */
class RestorePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('parties.party.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
