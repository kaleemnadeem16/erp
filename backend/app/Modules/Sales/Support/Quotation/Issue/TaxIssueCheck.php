<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;

/**
 * Whether the quotation's tax is determined well enough to issue (ADR 0119; QL0 record §5: issue refuses `TAX_CONTEXT_UNKNOWN` until a supported determination exists).
 * The Tax-owned FR3 determination plugs in here by rebinding this interface; until then `UndeterminedTaxIssueCheck` says it is not determined. An implementation names
 * blockers by code only, and must never turn an unknown into a pass.
 */
interface TaxIssueCheck
{
    /** @return list<IssueBlocker> empty only when the tax is determined with evidence for every line */
    public function blockers(SalesActorContext $actor, QuotationRevision $revision): array;
}
