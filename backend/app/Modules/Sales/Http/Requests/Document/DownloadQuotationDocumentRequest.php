<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Document;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;

/** Hands out the stored official PDF: `sales.document.download`. Default-deny: the duty is STAGED, so nobody passes until it is activated; the Action asks again and also needs the right to see the quotation. */
class DownloadQuotationDocumentRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::DOCUMENT_DOWNLOAD;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['intent' => ['sometimes', 'string', 'in:download,print']];
    }
}
