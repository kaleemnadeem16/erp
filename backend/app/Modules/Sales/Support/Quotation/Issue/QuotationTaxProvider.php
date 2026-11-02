<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;

/**
 * The one seam between Sales and the Tax module for issue (ADR 0119 / FR3). The real implementation builds a Tax context per line from the revision, asks Tax to determine and
 * calculate it, and — inside the issue transaction, under the agreed locks — re-checks that the evidence is still current. Sales never selects a rate, a jurisdiction law or a
 * profile. Unknown, unsupported, stale or expired evidence is UNKNOWN with blockers; never a zero.
 *
 * @internal Only the issue assessor calls it.
 */
interface QuotationTaxProvider
{
    /** @param  array<int, string|null>  $settledNets  line id => the line's settled net, or null where the net itself is not known yet */
    public function assess(SalesActorContext $actor, QuotationRevision $revision, array $settledNets): QuotationTaxOutcome;
}
