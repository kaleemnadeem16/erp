<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

/** Reading a quotation draft's safe metadata needs `sales.quotation.view` (STAGED: refused for everyone until a reviewed holder recipe activates it). */
class ShowQuotationDraftRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_VIEW;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
