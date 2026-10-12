<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Opportunity;

use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Foundation\Http\FormRequest;

/** Who a historical correction may name: only for someone who may make the correction (ADR 0114). */
class IndexOpportunityAttributionMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return SalesAccess::mayCorrectAttribution($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['filter' => ['sometimes', 'array:search,id'], 'filter.search' => ['sometimes', 'string', 'max:255'], 'filter.id' => ['sometimes', 'integer', 'min:1']];
    }
}
