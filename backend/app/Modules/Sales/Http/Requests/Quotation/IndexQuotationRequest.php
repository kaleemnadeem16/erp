<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Validation\Rule;

final class IndexQuotationRequest extends QuotationRequest
{
    use ValidatesListQuery;

    protected function capability(): string
    {
        return PermissionVocabulary::QUOTATION_VIEW;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:state,recipient_party_id,owner_id,mine'],
            'filter.state' => ['sometimes', Rule::in(['DRAFT', 'CANCELLED_DRAFT', 'ISSUED'])],
            'filter.recipient_party_id' => ['sometimes', 'integer', 'min:1'],
            'filter.owner_id' => ['sometimes', 'integer', 'min:1'],
            'filter.mine' => ['sometimes', 'boolean'],
        ] + $this->listQueryRules(['id', 'created_at', 'state'], []);
    }
}
