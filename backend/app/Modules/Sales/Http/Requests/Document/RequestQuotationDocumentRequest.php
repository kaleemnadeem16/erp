<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Document;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

/** Generates or retries the official PDF: `sales.document.generate`. Default-deny: the duty is STAGED, so nobody passes until it is activated; the Action asks again and also needs the right to see the quotation. */
class RequestQuotationDocumentRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::DOCUMENT_GENERATE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
