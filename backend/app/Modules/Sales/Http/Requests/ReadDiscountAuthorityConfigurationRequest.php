<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;

class ReadDiscountAuthorityConfigurationRequest extends DiscountAuthorityRequest
{
    protected function capability(): string
    {
        if (in_array($this->route('collection'), ['references', 'reference-approvals'], true) && ! SalesAccess::may($this->user(), PermissionVocabulary::FINANCIAL_CONFIGURE)) {
            return PermissionVocabulary::PRICE_REFERENCE_APPROVE;
        }

        return parent::capability();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }
}
