<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\Pricing\PricingResult;

/**
 * The seam where a cost-blind evaluation will obtain authorised cost for a priced line. A real implementation runs server-side under its own, narrow
 * authority (it is *not* a grant of `estimating.internal_cost.view` to the seller) and hands the evaluator the line's cost as honest certainty; the seller
 * only ever receives `NetControlEvaluation::consumerSafe()`. It must return UNKNOWN or PARTIAL rather than invent cost, and must not accept a
 * caller-supplied cost. No implementation is delivered: see `UnavailableControlEvidence`.
 */
interface ControlEvidenceProvider
{
    public function costFor(SalesActorContext $actor, PricingResult $priced): FinancialResult;
}
