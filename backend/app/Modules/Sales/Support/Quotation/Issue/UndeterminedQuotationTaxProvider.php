<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;

/** No tax determination is wired: the tax is not determined, and a quotation is never issued on an assumed zero. */
final class UndeterminedQuotationTaxProvider implements QuotationTaxProvider
{
    public function assess(SalesActorContext $actor, QuotationRevision $revision, array $settledNets): QuotationTaxOutcome
    {
        return QuotationTaxOutcome::unknown([new IssueBlocker('TAX_NOT_DETERMINED', IssueBlocker::TAX)]);
    }
}
