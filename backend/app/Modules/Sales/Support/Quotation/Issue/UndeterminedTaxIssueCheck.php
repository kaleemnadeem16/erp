<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;

/** No tax determination is delivered: the tax is not determined, and a quotation is never issued on an assumed zero. */
final class UndeterminedTaxIssueCheck implements TaxIssueCheck
{
    public function blockers(SalesActorContext $actor, QuotationRevision $revision): array
    {
        return [new IssueBlocker('TAX_NOT_DETERMINED', IssueBlocker::TAX)];
    }
}
