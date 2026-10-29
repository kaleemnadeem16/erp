<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Document;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

/** The status and timeline of an issued quotation's official PDF: `sales.document.view`. Default-deny: the duty is STAGED, so nobody passes until it is activated; the Action asks again and also needs the right to see the quotation. */
class ShowQuotationDocumentRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::DOCUMENT_VIEW;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
