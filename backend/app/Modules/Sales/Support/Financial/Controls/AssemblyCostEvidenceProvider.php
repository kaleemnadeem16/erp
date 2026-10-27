<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use Brick\Math\BigDecimal;

/**
 * Where the cost-blind evaluator obtains the cost of ONE Assembly version for ONE work quantity (#29b). The implementation runs server-side under its own narrow
 * authority — it is not `estimating.internal_cost.view` and nobody is granted it — and hands back honest certainty: complete only when Estimating says so, PARTIAL
 * with just what is known, otherwise UNKNOWN with a fixed reason code. It accepts no caller-supplied cost and never returns zero for an unknown. The seller only ever
 * receives `NetControlOutcomeView`; this value stays inside the trusted evaluation.
 */
interface AssemblyCostEvidenceProvider
{
    public function costFor(SalesActorContext $actor, int $assemblyVersionId, BigDecimal $quantity): FinancialResult;
}
