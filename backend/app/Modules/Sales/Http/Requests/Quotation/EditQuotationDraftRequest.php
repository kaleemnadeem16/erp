<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

/** Reopening a draft for editing is the preparer's duty (`sales.quotation.prepare`, STAGED), independent of `sales.quotation.view`. */
class EditQuotationDraftRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_PREPARE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
