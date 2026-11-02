<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;

/**
 * Whether the company's mandatory final-net controls were evaluated for every line and none blocks (FR4; QL0 record: an empty control set is not issue readiness). The real
 * implementation needs a configured control set and cost evidence for each line's source. Codes only; an UNEVALUABLE mandatory control is a blocker, never a pass.
 *
 * @internal Only the issue assessor calls it.
 */
interface QuotationControlsProvider
{
    /** @param  array<int, string|null>  $settledNets  line id => the line's settled net, or null where it is not known yet */
    public function assess(SalesActorContext $actor, QuotationRevision $revision, array $settledNets): QuotationControlsOutcome;
}
