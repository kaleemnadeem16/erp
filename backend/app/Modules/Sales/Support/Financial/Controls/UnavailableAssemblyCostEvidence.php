<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use Brick\Math\BigDecimal;

/** A provider that knows nothing: every cost is UNKNOWN. For a context with no Estimating evidence and for tests of the unavailable path. */
final class UnavailableAssemblyCostEvidence implements AssemblyCostEvidenceProvider
{
    public function costFor(SalesActorContext $actor, int $assemblyVersionId, BigDecimal $quantity): FinancialResult
    {
        return FinancialResult::unknown([['code' => 'COST_ADAPTER_NOT_DELIVERED']]);
    }
}
