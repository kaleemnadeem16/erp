<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\QuotationAccess;

use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Validation\Rule;

class QuotationAccessCandidatesRequest extends QuotationAccessRequest
{
    protected function duties(): array
    {
        return [Duty::QUOTATION_VIEW, $this->input('kind') === 'recipient' ? Duty::QUOTATION_ACCESS_DELEGATE : Duty::QUOTATION_ASSIGN,
            ...($this->input('kind') === 'recipient' && $this->input('level') === 'PREPARE' ? [Duty::QUOTATION_PREPARE] : [])];
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['kind' => ['required', Rule::in(['owner', 'recipient', 'unit'])], 'level' => ['nullable', Rule::in(['VIEW', 'PREPARE'])],
            'filter' => ['sometimes', 'array:search'], 'filter.search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'], 'cursor' => ['nullable', 'string', 'max:1024']];
    }
}
