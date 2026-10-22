<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Documents;

use App\Modules\Documents\Support\DocumentProvider;

/** The document types the Sales module owns; handed to the Documents registry from the Sales service provider. */
final class QuotationDocumentProvider implements DocumentProvider
{
    public function documentTypes(): array
    {
        return [new QuotationCustomerDocumentAdapter];
    }
}
