<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Document;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

/** Operator integrity check of the stored PDF: `sales.document.repair` (sensitive). Default-deny: the duty is STAGED, so nobody passes until it is activated; the Action asks again and also needs the right to see the quotation. */
class CheckQuotationDocumentRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::DOCUMENT_REPAIR;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
