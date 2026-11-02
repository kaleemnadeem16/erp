<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Tax\Support\TaxClassification;

/** No jurisdiction or classification source is bound: nothing is known, so no line can be taxed and nothing can be issued. */
final class UnavailableQuotationTaxFacts implements QuotationTaxFacts
{
    public function jurisdiction(string $taxDate): ?string
    {
        return null;
    }

    public function classification(QuotationRevision $revision, QuotationDraftLine $line): ?TaxClassification
    {
        return null;
    }
}
