<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Support\PermissionVocabulary;

class ApproveSalesPriceReferenceRequest extends ChangeDiscountAuthorityRequest
{
    protected function capability(): string
    {
        return PermissionVocabulary::PRICE_REFERENCE_APPROVE;
    }
}
